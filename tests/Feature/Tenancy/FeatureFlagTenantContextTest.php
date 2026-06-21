<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/*
| W1 — Pennant database store is pinned to the CENTRAL connection.
|
| The `features` table migration lives ONLY in the central DB; it is NOT in the
| tenant migrations. With the store's connection left null (the tenant-swapped
| default), resolving a flag INSIDE tenant context would hit the tenant DB and
| throw 42P01 "relation features does not exist" — 500-ing any tenant route that
| gates on a feature (the whole point of Pennant in a SaaS).
|
| This drives a REAL tenant DB (CREATE DATABASE is forbidden inside a tx → no
| RefreshDatabase), initializes tenancy, and resolves a flag while the default
| connection IS the tenant connection. It must NOT throw and must return a bool.
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
    cleanCentralRegistry();
});

it('resolves a Pennant feature flag INSIDE tenant context without a missing-table error (W1)', function () use (&$provisioned): void {
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: 'Flags Co',
        subdomain: 'flags',
        ownerEmail: 'owner@flags.test',
        plan: TenantPlan::Business,
    ));
    $provisioned = [$tenant];

    // Physically create + migrate the tenant DB so we genuinely sit in tenant
    // context (the tenant DB has NO `features` table — that is the point).
    (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));
    tenancy()->initialize($tenant);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--realpath' => false,
        '--force' => true,
    ]);

    // We ARE in tenant context: the default connection is the tenant DB.
    expect(DB::getDefaultConnection())->toBe('tenant');

    // Resolving a flag must hit CENTRAL (pinned store), never the tenant DB —
    // no 42P01, and a real bool comes back (Business → advanced analytics ON).
    $active = Feature::for($tenant)->active(TenantFeature::AdvancedAnalytics->value);

    expect($active)->toBeBool()->toBeTrue();

    // A Free-plan flag resolution is also fine inside tenant context.
    expect(Feature::for($tenant)->active(TenantFeature::SsoSaml->value))->toBeFalse();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
