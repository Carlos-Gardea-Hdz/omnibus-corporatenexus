<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Jobs\MarkTenantActive;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;

/**
 * The QUEUED provisioning pipeline, exercised end-to-end on REAL PostgreSQL 18.
 *
 * PostgreSQL forbids CREATE DATABASE inside a transaction, so — exactly like the
 * foundation CrossTenantIsolationTest — this suite does NOT use RefreshDatabase
 * (which holds an open central transaction). It cleans the central registry by
 * hand outside any transaction and drops every tenant DB it creates.
 *
 * We drain the pipeline SYNCHRONOUSLY by invoking each stage's handle() directly
 * (CreateDatabase → migrate → SeedDatabase → MarkTenantActive), the same shape
 * stancl runs on a queue worker, and assert the physical tenant DB exists, the
 * tenant migrations ran, the seed rows landed, and the central status flipped
 * to Active.
 *
 * Calling ->handle() directly also sidesteps Pest.php's Queue::fake() (which
 * only intercepts dispatched jobs, never a direct handle()).
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
});

it('creates the tenant database, runs tenant migrations, seeds it, and flips status to Active', function () use (&$provisioned): void {
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: 'Pipeline Co',
        subdomain: 'pipeline',
        ownerEmail: 'owner@pipeline.test',
        plan: TenantPlan::Business,
    ));
    $provisioned = [$tenant];

    // Central registry starts Pending — provisioning has not run yet.
    expect($tenant->status)->toBe(TenantStatus::Pending);

    // --- Drain the pipeline synchronously, outside any transaction ---------
    // 1) Physical tenant database.
    (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));

    // 2) Tenant migrations (projects + tasks + users live under migrations/tenant).
    tenancy()->initialize($tenant);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--realpath' => false,
        '--force' => true,
    ]);

    // The tenant DB physically holds the Work tables (proof migrations ran).
    expect(Schema::hasTable('projects'))->toBeTrue()
        ->and(Schema::hasTable('tasks'))->toBeTrue();
    tenancy()->end();

    // 3) Seed the tenant DB with fictional demo work (TenantDatabaseSeeder).
    (new SeedDatabase($tenant))->handle();

    // 4) Activation job — central status flips Pending → Active.
    (new MarkTenantActive($tenant))->handle();

    // --- Assertions --------------------------------------------------------
    // Seed rows landed inside the tenant DB.
    $seededCount = $tenant->run(fn (): int => Project::query()->count());
    expect($seededCount)->toBeGreaterThan(0);

    // Central status flipped to Active (re-read from the central connection).
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Active);
});

it('activates idempotently — re-running MarkTenantActive keeps the tenant Active', function () use (&$provisioned): void {
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: 'Idempotent Co',
        subdomain: 'idem',
        ownerEmail: 'owner@idem.test',
    ));
    $provisioned = [$tenant];

    (new MarkTenantActive($tenant))->handle();
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Active);

    // Running it again must be a safe no-op (Active cannot transition to Active).
    (new MarkTenantActive($tenant))->handle();
    $tenant->refresh();
    expect($tenant->status)->toBe(TenantStatus::Active);
});
