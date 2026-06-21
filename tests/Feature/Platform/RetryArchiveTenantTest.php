<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Events\TenantCreated;

/*
| W3 — operator recovery for stuck/failed tenants.
|
| A Failed (or stuck Pending) tenant used to be a dead end: the console only
| offered suspend/reactivate/changePlan. We add RETRY (Failed → Pending + a
| re-dispatch of the provisioning pipeline) and ARCHIVE (→ Archived). Both are
| gated by TenantStatus::canTransitionTo() (default-deny): an illegal request is
| a graceful 302 + error with the status UNCHANGED, never a 500.
|
| Central registry only → RefreshDatabase suffices.
*/

uses(RefreshDatabase::class);

function recoveryHost(): string
{
    return (string) config('app.central_domain');
}

function recoveryTenant(TenantStatus $status): Tenant
{
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => 'Recovery Co',
        'status' => $status,
        'plan' => TenantPlan::Business,
    ]);

    Domain::create([
        'domain' => 'recovery.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    return $tenant;
}

it('retries a Failed tenant (Failed→Pending) and re-dispatches the provisioning pipeline', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = recoveryTenant(TenantStatus::Failed);

    // Fake AFTER creation so the assertion observes ONLY the retry replay, not the
    // factory's own `created` → TenantCreated.
    Event::fake([TenantCreated::class]);

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.recoveryHost().route('platform.tenants.retry', $tenant, absolute: false));

    $response->assertStatus(302)->assertSessionHasNoErrors();

    // Central status flipped back to Pending, ready for the replayed pipeline.
    expect($tenant->refresh()->status)->toBe(TenantStatus::Pending);

    // The stancl provisioning pipeline was re-fired (queued in prod) for THIS tenant.
    Event::assertDispatched(
        TenantCreated::class,
        fn (TenantCreated $event): bool => $event->tenant->is($tenant),
    );
});

it('archives a Failed tenant (Failed→Archived) with 302 + flash', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = recoveryTenant(TenantStatus::Failed);

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.recoveryHost().route('platform.tenants.archive', $tenant, absolute: false));

    $response->assertStatus(302)->assertSessionHasNoErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Archived);
});

it('archives a stuck Pending tenant (Pending→Archived) too', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = recoveryTenant(TenantStatus::Pending);

    $this->actingAs($admin, 'admin')
        ->patch('http://'.recoveryHost().route('platform.tenants.archive', $tenant, absolute: false))
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Archived);
});

it('refuses an illegal retry (an Active tenant cannot be retried): 302 + error, status unchanged, NOT 500', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = recoveryTenant(TenantStatus::Active);

    // Fake AFTER the factory created the tenant (which itself fires stancl's
    // TenantCreated on `created`) so we only observe a RETRY replay, if any.
    Event::fake([TenantCreated::class]);

    // Sanity: the graph forbids Active→Pending.
    expect(TenantStatus::Active->canTransitionTo(TenantStatus::Pending))->toBeFalse();

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.recoveryHost().route('platform.tenants.retry', $tenant, absolute: false));

    $response->assertStatus(302)->assertSessionHasErrors();

    // Status untouched and NO pipeline replay was triggered.
    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);
    Event::assertNotDispatched(TenantCreated::class);
});

it('refuses an illegal archive (an Archived tenant is terminal): 302 + error, status unchanged, NOT 500', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = recoveryTenant(TenantStatus::Archived);

    expect(TenantStatus::Archived->canTransitionTo(TenantStatus::Archived))->toBeFalse();

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.recoveryHost().route('platform.tenants.archive', $tenant, absolute: false));

    $response->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Archived);
});

it('exposes can.retry only for a Failed tenant and can.archive for non-terminal tenants', function (): void {
    $admin = PlatformAdmin::factory()->create();

    $failed = recoveryTenant(TenantStatus::Failed);

    $this->actingAs($admin, 'admin')
        ->get('http://'.recoveryHost().route('platform.tenants.show', $failed, absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.retry', true)
            ->where('can.archive', true));

    $active = Tenant::factory()->create([
        'name' => 'Active Co',
        'status' => TenantStatus::Active,
        'plan' => TenantPlan::Business,
    ]);
    Domain::create(['domain' => 'activeco.'.config('app.central_domain'), 'tenant_id' => $active->getKey()]);

    $this->actingAs($admin, 'admin')
        ->get('http://'.recoveryHost().route('platform.tenants.show', $active, absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.retry', false)
            ->where('can.archive', true));
});
