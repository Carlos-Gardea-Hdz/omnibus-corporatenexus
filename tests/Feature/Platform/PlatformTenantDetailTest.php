<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Platform tenant detail page (CONTRACT §7 test list 8).
 *
 * The detail surfaces the central registry record: status/plan, the live
 * seat_limit + price_cents (read from the plan, never stored), the plan's
 * resolved feature list, the allowed lifecycle transitions DERIVED from
 * TenantStatus::canTransitionTo() (never a hardcoded pair), and the per-action
 * capability flags. Central connection only → RefreshDatabase suffices.
 */
uses(RefreshDatabase::class);

function detailHost(): string
{
    return (string) config('app.central_domain');
}

function detailTenant(TenantStatus $status, TenantPlan $plan): Tenant
{
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => 'Detail Co',
        'status' => $status,
        'plan' => $plan,
    ]);

    Domain::create([
        'domain' => 'detail.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    return $tenant;
}

it('renders the exact tenant detail shape with live seat/price and transitions from canTransitionTo()', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = detailTenant(TenantStatus::Active, TenantPlan::Team);

    $this->actingAs($admin, 'admin')
        ->get('http://'.detailHost().route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Tenants/Show')
            ->where('admin.id', $admin->getKey())
            ->where('tenant.id', $tenant->id)
            ->where('tenant.status', TenantStatus::Active->value)
            ->where('tenant.plan', TenantPlan::Team->value)
            ->where('tenant.seat_limit', TenantPlan::Team->seatLimit())
            ->where('tenant.price_cents', TenantPlan::Team->priceCents())
            ->has('tenant.name')
            ->has('tenant.subdomain')
            ->has('tenant.owner_email')
            ->has('tenant.created_at')
            ->has('tenant.status_label')
            ->has('tenant.status_color')
            ->has('tenant.plan_label')
            ->has('tenant.features', 4, fn ($feature) => $feature
                ->has('value')
                ->has('label')
                ->has('active')
                ->etc()
            )
            ->has('tenant.over_seat_limit')
            ->has('tenant.seats_over')
            ->has('allowed_transitions')
            ->has('assignable_plans')
            ->has('can.suspend')
            ->has('can.reactivate')
            ->has('can.change_plan')
        );
});

it('derives allowed_transitions and can.* from the live status graph (Active can suspend, not reactivate)', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = detailTenant(TenantStatus::Active, TenantPlan::Business);

    $this->actingAs($admin, 'admin')
        ->get('http://'.detailHost().route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array<string, mixed>> $transitions */
            $transitions = $page->toArray()['props']['allowed_transitions'];
            $targets = array_column($transitions, 'value');

            // Active → {Suspended, Archived} per TenantStatus::canTransitionTo().
            expect($targets)->toContain(TenantStatus::Suspended->value)
                ->and($targets)->toContain(TenantStatus::Archived->value)
                ->and($targets)->not->toContain(TenantStatus::Active->value);

            $can = $page->toArray()['props']['can'];
            // Active is servable → suspend is available, reactivate is not.
            expect($can['suspend'])->toBeTrue()
                ->and($can['reactivate'])->toBeFalse();
        });
});

it('derives the reactivate capability for a Suspended tenant (Suspended can reactivate, not suspend)', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = detailTenant(TenantStatus::Suspended, TenantPlan::Business);

    $this->actingAs($admin, 'admin')
        ->get('http://'.detailHost().route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $can = $page->toArray()['props']['can'];

            // Suspended → {Active, Archived}: reactivate yes, suspend no.
            expect($can['reactivate'])->toBeTrue()
                ->and($can['suspend'])->toBeFalse();

            /** @var array<int, array<string, mixed>> $transitions */
            $transitions = $page->toArray()['props']['allowed_transitions'];
            $targets = array_column($transitions, 'value');
            expect($targets)->toContain(TenantStatus::Active->value);
        });
});
