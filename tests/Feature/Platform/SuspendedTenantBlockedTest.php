<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * THE teeth of slice 003 (CONTRACT test list 12): a SUSPENDED tenant is blocked
 * from SERVING its application.
 *
 * The EnsureTenantIsActive middleware sits in the tenant route group AFTER
 * identification: once the suspended tenant boots, the request is rejected
 * (503 maintenance per OQ-2) BEFORE any tenant page renders. Flipping the
 * central status back to Active lifts the block — the SAME request then serves
 * 200. This is falsifiable: an Active tenant always serves, a Suspended one
 * never does, and the only thing that changed is the central status column.
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE forbidden inside a
 * transaction) — mirrors TenantIdentificationTest.
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

function provisionBlockTenant(string $name, string $subdomain): Tenant
{
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: $name,
        subdomain: $subdomain,
        ownerEmail: "owner@{$subdomain}.test",
        plan: TenantPlan::Business,
    ));

    (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));
    tenancy()->initialize($tenant);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--realpath' => false,
        '--force' => true,
    ]);
    tenancy()->end();

    // Synchronously provisioned → mark Active so the Active baseline serves 200
    // (the test then suspends/reactivates on the central registry).
    return markTenantActive($tenant);
}

it('blocks a suspended tenant from serving and resumes it on reactivate (503 ↔ 200)', function () use (&$provisioned): void {
    $tenant = provisionBlockTenant('Blocked Co', 'blockedco');
    $provisioned = [$tenant];

    $host = (string) $tenant->domains()->value('domain');

    // Baseline: an Active tenant serves its landing page normally.
    $this->get('http://'.$host.'/')->assertOk();
    tenancy()->end();

    // Suspend on the CENTRAL registry (the console action's effect).
    $tenant->update(['status' => TenantStatus::Suspended]);
    tenancy()->end();

    // The SAME request is now refused: 503 maintenance (the tenant cannot serve).
    $this->get('http://'.$host.'/')->assertStatus(503);
    tenancy()->end();

    // The tenant login is blocked too — the gate sits above the whole group.
    $this->get('http://'.$host.route('tenant.login', absolute: false))->assertStatus(503);
    tenancy()->end();

    // Reactivate on the CENTRAL registry → the block lifts, the page serves 200.
    $tenant->update(['status' => TenantStatus::Active]);
    tenancy()->end();

    $this->get('http://'.$host.'/')->assertOk();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
