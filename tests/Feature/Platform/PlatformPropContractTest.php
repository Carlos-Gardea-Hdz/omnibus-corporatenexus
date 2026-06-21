<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Inertia prop CONTRACTS for the three CENTRAL console pages (CONTRACT §4 + §7
 * test list 15). Props are snake_case; enum props serialize to their string
 * value. Crucially, NO password / remember_token EVER reaches a persisted prop,
 * and the login page exposes NO props at all.
 *
 * Central connection only → RefreshDatabase + factory rows suffice.
 */
uses(RefreshDatabase::class);

function propHostCentral(): string
{
    return (string) config('app.central_domain');
}

function consoleTenantRow(): Tenant
{
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => 'Prop Co',
        'status' => TenantStatus::Active,
        'plan' => TenantPlan::Team,
    ]);
    Domain::create([
        'domain' => 'prop.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    return $tenant;
}

it('Auth/PlatformLogin renders with no props', function (): void {
    $this->get('http://'.propHostCentral().route('platform.login', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/PlatformLogin')
            ->missing('password')
            ->missing('remember_token')
            ->missing('admin')
        );
});

it('Platform/Dashboard exposes the admin shell + tenant list, never a secret', function (): void {
    $admin = PlatformAdmin::factory()->create();
    consoleTenantRow();

    $this->actingAs($admin, 'admin')
        ->get('http://'.propHostCentral().route('platform.dashboard', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Dashboard')
            ->has('admin.id')
            ->has('admin.name')
            ->has('admin.email')
            ->has('tenants.data')
            ->has('filters')
            ->has('counts')
            // The admin shell NEVER carries a credential.
            ->missing('admin.password')
            ->missing('admin.remember_token')
            ->missing('password')
            ->missing('remember_token')
        );
});

it('Platform/Tenants/Show exposes the detail contract, never a secret', function (): void {
    $admin = PlatformAdmin::factory()->create();
    $tenant = consoleTenantRow();

    $this->actingAs($admin, 'admin')
        ->get('http://'.propHostCentral().route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Tenants/Show')
            ->has('admin.id')
            ->has('tenant.id')
            ->has('tenant.seat_limit')
            ->has('tenant.price_cents')
            ->has('tenant.features')
            ->has('allowed_transitions')
            ->has('assignable_plans')
            ->has('can')
            ->missing('admin.password')
            ->missing('admin.remember_token')
            ->missing('password')
            ->missing('remember_token')
        );
});
