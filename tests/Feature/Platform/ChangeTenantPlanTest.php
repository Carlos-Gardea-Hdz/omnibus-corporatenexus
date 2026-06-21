<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Change-plan from the console (CONTRACT §7 test list 12–13).
 *
 * Changing a tenant's plan rewrites the CENTRAL `plan` column; the seat limit is
 * NOT stored — it is read LIVE from the new plan (TenantPlan::seatLimit()) — and
 * Pennant features re-resolve from the plan. A DOWNGRADE that lands the plan's
 * seat ceiling BELOW the current member count GRANDFATHERS the existing members:
 * the change succeeds, the detail flags the over-limit condition, and NO member
 * is removed (no live seat reconciliation this slice — OQ-1).
 */
it('changes the plan (Team→Business): central plan updated, seat_limit reflects the NEW plan, a newly-unlocked feature resolves active', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'name' => 'Upgrade Co',
        'status' => TenantStatus::Active,
        'plan' => TenantPlan::Team,
    ]);
    Domain::create([
        'domain' => 'upgrade.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    $admin = PlatformAdmin::factory()->create();

    $response = $this->actingAs($admin, 'admin')
        ->patch('http://'.config('app.central_domain').route('platform.tenants.plan', $tenant, absolute: false), [
            'plan' => TenantPlan::Business->value,
        ]);

    $response->assertStatus(302)
        ->assertSessionHasNoErrors();

    $tenant->refresh();

    // Central plan column rewritten; the seat limit is read LIVE from the plan.
    expect($tenant->plan)->toBe(TenantPlan::Business)
        ->and($tenant->plan->seatLimit())->toBe(TenantPlan::Business->seatLimit())
        ->and(TenantPlan::Business->seatLimit())->toBeGreaterThan(TenantPlan::Team->seatLimit());

    // AdvancedAnalytics is gated to Business+Enterprise (AppServiceProvider) — it
    // was OFF under Team and must now resolve ACTIVE on the detail feature list.
    $this->actingAs($admin, 'admin')
        ->get('http://'.config('app.central_domain').route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(function ($page): void {
            $detail = $page->toArray()['props']['tenant'];

            expect($detail['plan'])->toBe(TenantPlan::Business->value)
                ->and($detail['seat_limit'])->toBe(TenantPlan::Business->seatLimit());

            $analytics = collect($detail['features'])
                ->firstWhere('value', TenantFeature::AdvancedAnalytics->value);

            expect($analytics)->not->toBeNull()
                ->and($analytics['active'])->toBeTrue();
        });
})->uses(RefreshDatabase::class);

/*
| Downgrade grandfathering — needs REAL members in a tenant DB. Real PostgreSQL,
| no RefreshDatabase (CREATE DATABASE forbidden inside a transaction). The change
| MUST succeed and remove NO member; the over-limit flags must be present and
| internally consistent (under OQ-1 default (c) the count seam may report
| over_seat_limit=false/seats_over=0; under OQ-1(a) it reports the real overflow).
*/

/** @var list<Tenant> $provisioned */
$provisioned = [];

beforeEach(function (): void {
    tenancy()->end();
    DB::table('domains')->delete();
    Tenant::query()->cursor()->each(function (Tenant $tenant): void {
        try {
            $tenant->database()->makeCredentials();
            $tenant->database()->manager()->deleteDatabase($tenant);
        } catch (Throwable) {
            // Ignore: tenant DB may not exist.
        }
    });
    DB::table('tenants')->delete();
    PlatformAdmin::query()->delete();
});

afterEach(function () use (&$provisioned): void {
    tenancy()->end();
    foreach ($provisioned as $tenant) {
        try {
            $tenant->database()->makeCredentials();
            $tenant->database()->manager()->deleteDatabase($tenant);
        } catch (Throwable) {
            // Best-effort cleanup.
        }
    }
    $provisioned = [];
    PlatformAdmin::query()->delete();
});

it('grandfathers existing members on a downgrade: change succeeds, no member removed, over-limit flags consistent', function () use (&$provisioned): void {
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: 'Downgrade Co',
        subdomain: 'downgrade',
        ownerEmail: 'owner@downgrade.test',
        plan: TenantPlan::Business, // seat limit 100
    ));
    $provisioned = [$tenant];

    (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));
    tenancy()->initialize($tenant);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--realpath' => false,
        '--force' => true,
    ]);
    tenancy()->end();

    // Seed M members in the tenant DB, with M ABOVE the Free seat ceiling (3).
    $memberCount = TenantPlan::Free->seatLimit() + 2; // 5 > 3
    $tenant->run(function () use ($memberCount): void {
        User::factory()->owner()->create(['email' => 'owner@downgrade.test']);
        User::factory()->count($memberCount - 1)->create();
    });

    expect($tenant->run(fn (): int => User::query()->count()))->toBe($memberCount)
        ->and($memberCount)->toBeGreaterThan(TenantPlan::Free->seatLimit());

    $admin = PlatformAdmin::factory()->create();

    // Downgrade Business → Free (seat ceiling drops from 100 to 3 < 5 members).
    $this->actingAs($admin, 'admin')
        ->patch('http://'.config('app.central_domain').route('platform.tenants.plan', $tenant, absolute: false), [
            'plan' => TenantPlan::Free->value,
        ])->assertStatus(302)->assertSessionHasNoErrors();

    $tenant->refresh();

    // The plan changed and the live seat limit reflects the SMALLER plan.
    expect($tenant->plan)->toBe(TenantPlan::Free)
        ->and($tenant->plan->seatLimit())->toBe(TenantPlan::Free->seatLimit());

    // NOT A SINGLE member was removed — the downgrade grandfathers them.
    expect($tenant->run(fn (): int => User::query()->count()))->toBe($memberCount);

    // The detail page exposes the over-limit flags, internally consistent:
    // over_seat_limit is true IFF seats_over > 0; a seam reporting no count is
    // false/0 (OQ-1 default (c)), a live count is true/(M-N) (OQ-1(a)).
    $this->actingAs($admin, 'admin')
        ->get('http://'.config('app.central_domain').route('platform.tenants.show', $tenant, absolute: false))
        ->assertOk()
        ->assertInertia(function ($page) use ($memberCount): void {
            $detail = $page->toArray()['props']['tenant'];

            expect($detail)->toHaveKey('over_seat_limit')
                ->and($detail)->toHaveKey('seats_over')
                ->and($detail['over_seat_limit'])->toBeBool()
                ->and($detail['seats_over'])->toBeInt()
                ->and($detail['seats_over'])->toBeGreaterThanOrEqual(0);

            // Consistency: the flag is true exactly when the overflow is positive.
            expect($detail['over_seat_limit'])->toBe($detail['seats_over'] > 0);

            // If the seam reports a live overflow, it equals M - N.
            if ($detail['seats_over'] > 0) {
                expect($detail['seats_over'])->toBe($memberCount - TenantPlan::Free->seatLimit());
            }
        });

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
