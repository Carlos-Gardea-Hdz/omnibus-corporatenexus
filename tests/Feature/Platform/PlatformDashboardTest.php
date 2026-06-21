<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Platform dashboard — the tenant registry list (CONTRACT §7 test list 6–7).
 *
 * The console reads the CENTRAL tenants registry only: every tenant row, its
 * status/plan/owner-email summary, and an UNFILTERED status tally. Status/plan
 * filters narrow the LIST but never the counts. No tenant DB is provisioned —
 * the registry lives on the central connection, so RefreshDatabase suffices.
 */
uses(RefreshDatabase::class);

/*
| The real-DB tenancy suites (no RefreshDatabase) COMMIT central registry rows.
| RefreshDatabase only runs its one-time migrate:fresh for the FIRST such test
| in a run; a committed leftover from a non-RD suite is then visible inside this
| test's transaction and would skew the exact tenant-count assertions below.
| Clear the registry up front so the counts are deterministic regardless of
| suite ordering; the transaction still rolls everything back afterwards.
*/
beforeEach(function (): void {
    cleanCentralRegistry();
});

function dashboardHost(): string
{
    return (string) config('app.central_domain');
}

/** Create a central registry tenant with a bound subdomain. */
function registryTenant(string $name, string $subdomain, TenantStatus $status, TenantPlan $plan): Tenant
{
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => $name,
        'status' => $status,
        'plan' => $plan,
    ]);

    Domain::create([
        'domain' => $subdomain.'.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    return $tenant;
}

it('lists every central tenant with the exact summary shape and an unfiltered status tally', function (): void {
    $admin = PlatformAdmin::factory()->create();

    registryTenant('Active Free Co', 'activefree', TenantStatus::Active, TenantPlan::Free);
    registryTenant('Active Team Co', 'activeteam', TenantStatus::Active, TenantPlan::Team);
    registryTenant('Suspended Biz Co', 'susbiz', TenantStatus::Suspended, TenantPlan::Business);
    registryTenant('Pending Ent Co', 'pendent', TenantStatus::Pending, TenantPlan::Enterprise);

    $this->actingAs($admin, 'admin')
        ->get('http://'.dashboardHost().route('platform.dashboard', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Dashboard')
            ->where('admin.id', $admin->getKey())
            ->where('admin.email', $admin->email)
            ->has('tenants.data', 4, fn ($row) => $row
                ->has('id')
                ->has('name')
                ->has('subdomain')
                ->has('status')
                ->has('status_label')
                ->has('status_color')
                ->has('plan')
                ->has('plan_label')
                ->has('owner_email')
                ->has('created_at')
                ->etc()
            )
            ->has('tenants.current_page')
            ->has('tenants.last_page')
            ->has('tenants.per_page')
            ->where('tenants.total', 4)
            ->has('status_options')
            ->has('plan_options')
            ->where('counts.total', 4)
            // The status tally is UNFILTERED — every distinct status is counted.
            ->where('counts.by_status.'.TenantStatus::Active->value, 2)
            ->where('counts.by_status.'.TenantStatus::Suspended->value, 1)
            ->where('counts.by_status.'.TenantStatus::Pending->value, 1)
        );
});

it('narrows the list by status + plan while leaving the counts unfiltered', function (): void {
    $admin = PlatformAdmin::factory()->create();

    registryTenant('Suspended Team A', 'susteama', TenantStatus::Suspended, TenantPlan::Team);
    registryTenant('Suspended Team B', 'susteamb', TenantStatus::Suspended, TenantPlan::Team);
    registryTenant('Suspended Biz', 'susbiz2', TenantStatus::Suspended, TenantPlan::Business);
    registryTenant('Active Team', 'actteam', TenantStatus::Active, TenantPlan::Team);

    $this->actingAs($admin, 'admin')
        ->get('http://'.dashboardHost().route('platform.dashboard', absolute: false)
            .'?status='.TenantStatus::Suspended->value.'&plan='.TenantPlan::Team->value)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Platform/Dashboard')
            // The list is narrowed to Suspended + Team → exactly 2 rows.
            ->where('tenants.total', 2)
            ->where('filters.status', TenantStatus::Suspended->value)
            ->where('filters.plan', TenantPlan::Team->value)
            // Counts stay over the WHOLE registry, independent of the filters.
            ->where('counts.total', 4)
            ->where('counts.by_status.'.TenantStatus::Suspended->value, 3)
            ->where('counts.by_status.'.TenantStatus::Active->value, 1)
        );
});

it('rejects a guest at the dashboard (auth:admin gate)', function (): void {
    Tenant::factory()->create();

    $this->get('http://'.dashboardHost().route('platform.dashboard', absolute: false))
        ->assertStatus(302)
        ->assertRedirect('http://'.dashboardHost().route('platform.login', absolute: false));
});
