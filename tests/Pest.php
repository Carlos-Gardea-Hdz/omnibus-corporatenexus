<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Tenant provisioning is QUEUED
|--------------------------------------------------------------------------
| In production the TenantCreated → CreateDatabase + MigrateDatabase pipeline
| runs on a queue worker (see TenancyServiceProvider): PostgreSQL forbids
| CREATE DATABASE inside the central transaction. With QUEUE_CONNECTION=sync a
| queued job would otherwise run inline inside that transaction and fail, so we
| fake the queue by default. Tests that need a real tenant database (the
| cross-tenant isolation suite) provision it explicitly, outside any
| transaction, and opt out of this fake.
*/
pest()->beforeEach(function (): void {
    Queue::fake();
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations & Helpers
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| markTenantActive — flip a synchronously-provisioned test tenant to Active
|--------------------------------------------------------------------------
| In production MarkTenantActive runs as the last queued provisioning job, but
| the queue is faked in tests. A test that synchronously provisions a tenant DB
| (migrations applied) has a tenant that IS ready to serve, yet its central
| `status` is still Pending — so the EnsureTenantIsActive middleware would 503
| any tenant-route request. Call this AFTER the DB/migrations are provisioned in
| the access-helpers to mark the central registry record Active (the effect of
| the real MarkTenantActive job), without weakening the middleware. It does NOT
| run the per-tenant SeedDatabase step, so tests that assert a Pending
| provisioning-status page must NOT call it.
*/
function markTenantActive(Tenant $tenant): Tenant
{
    $tenant->update(['status' => TenantStatus::Active]);

    return $tenant;
}

/*
|--------------------------------------------------------------------------
| cleanCentralRegistry — symmetric teardown for non-RefreshDatabase suites
|--------------------------------------------------------------------------
| The real-PostgreSQL provisioning suites (CREATE DATABASE forbidden inside a
| transaction → no RefreshDatabase) COMMIT central `domains`/`tenants` rows.
| Their beforeEach wipes the registry, but their afterEach historically dropped
| only the physical tenant databases — so the LAST such test leaks its committed
| central rows into any later RefreshDatabase suite (which never wipes the
| registry), polluting tenant counts. Call this in afterEach to remove those
| committed rows symmetrically. Central-only; never enters tenant context.
*/
function cleanCentralRegistry(): void
{
    DB::table('domains')->delete();
    DB::table('tenants')->delete();
}
