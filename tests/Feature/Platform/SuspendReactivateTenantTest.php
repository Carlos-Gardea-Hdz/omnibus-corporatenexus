<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Tenant lifecycle transitions from the console (CONTRACT §7 test list 9–11).
 *
 * Suspend (Active→Suspended) and reactivate (Suspended→Active) flip the CENTRAL
 * status column, returning 302 + a success flash. An ILLEGAL transition (e.g.
 * suspending a Pending tenant, or reactivating an Active one) is a graceful
 * 302 + error with the status UNCHANGED — never a 500. The Actions delegate the
 * legality decision to TenantStatus::canTransitionTo() (never a hardcoded pair).
 *
 * Central registry only → RefreshDatabase suffices (the suspended-tenant SERVING
 * block on a real tenant DB lives in SuspendedTenantBlockedTest).
 */
uses(RefreshDatabase::class);

function lifecycleHost(): string
{
    return (string) config('app.central_domain');
}

function lifecycleTenant(TenantStatus $status): Tenant
{
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => 'Lifecycle Co',
        'status' => $status,
        'plan' => TenantPlan::Business,
    ]);

    Domain::create([
        'domain' => 'lifecycle.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    return $tenant;
}

it('suspends an Active tenant (Active→Suspended) with 302 + flash', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = lifecycleTenant(TenantStatus::Active);

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.lifecycleHost().route('platform.tenants.suspend', $tenant, absolute: false));

    $response->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Suspended);
});

it('reactivates a Suspended tenant (Suspended→Active) with 302 + flash', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = lifecycleTenant(TenantStatus::Suspended);

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.lifecycleHost().route('platform.tenants.reactivate', $tenant, absolute: false));

    $response->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);
});

it('refuses an illegal suspend (a Pending tenant cannot be suspended): 302 + error, status unchanged, NOT 500', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = lifecycleTenant(TenantStatus::Pending);

    // Sanity: the graph itself forbids Pending→Suspended.
    expect(TenantStatus::Pending->canTransitionTo(TenantStatus::Suspended))->toBeFalse();

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.lifecycleHost().route('platform.tenants.suspend', $tenant, absolute: false));

    // Graceful: a 302 with an error, never a 500.
    $response->assertStatus(302)
        ->assertSessionHasErrors();

    // The status was NOT mutated by the rejected transition.
    expect($tenant->refresh()->status)->toBe(TenantStatus::Pending);
});

it('refuses an illegal reactivate (an Active tenant cannot be reactivated): 302 + error, status unchanged, NOT 500', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = lifecycleTenant(TenantStatus::Active);

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.lifecycleHost().route('platform.tenants.reactivate', $tenant, absolute: false));

    $response->assertStatus(302)
        ->assertSessionHasErrors();

    expect($tenant->refresh()->status)->toBe(TenantStatus::Active);
});
