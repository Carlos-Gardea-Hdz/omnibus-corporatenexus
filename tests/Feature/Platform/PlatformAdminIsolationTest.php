<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * THE crown of slice 003 (CONTRACT test list 5): platform-admin ↔ tenant-user
 * AUTH isolation across the central/tenant boundary.
 *
 * A tenant user U exists ONLY in tenant A's physical `users` table — the
 * IDENTICAL credentials {U.email, P} authenticate on A's tenant host (the `web`
 * guard, inside tenant context) but are REJECTED at the central console login
 * (the `admin` guard authenticates against `platform_admins`, where U is
 * physically absent). Conversely, a platform admin's credentials do NOT
 * authenticate against tenant A's `web` guard. The two identity stores never
 * cross.
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE is forbidden inside a
 * transaction) — mirrors the foundation cross-tenant isolation suite.
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

function provisionConsoleTenant(string $name, string $subdomain): Tenant
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

    // Synchronously provisioned → mark Active so the tenant login form serves
    // (this test hits tenant A's /login to prove admin creds are rejected there).
    return markTenantActive($tenant);
}

it('rejects a tenant user at the central console login and rejects a platform admin at the tenant login', function () use (&$provisioned): void {
    $tenantA = provisionConsoleTenant('Alpha Console Org', 'alphaconsole');
    $provisioned = [$tenantA];

    // A tenant user U lives ONLY in tenant A's physical database.
    $tenantEmail = 'staff@alphaconsole.test';
    $tenantPassword = 'Sup3r-Tenant-Pass!';
    $tenantA->run(function () use ($tenantEmail, $tenantPassword): void {
        User::factory()->owner()->create(['email' => $tenantEmail, 'password' => $tenantPassword]);
    });

    // A platform admin lives ONLY in the central platform_admins table.
    $adminEmail = 'operator@nexus.test';
    $adminPassword = 'Sup3r-Admin-Pass!';
    PlatformAdmin::factory()->create(['email' => $adminEmail, 'password' => $adminPassword]);

    $centralHost = (string) config('app.central_domain');
    $tenantHost = (string) $tenantA->domains()->value('domain');

    // U is physically absent from platform_admins (the substrate of the isolation).
    expect(PlatformAdmin::query()->where('email', $tenantEmail)->exists())->toBeFalse();

    // --- The tenant user's creds are REJECTED at the central console login ----
    $this->post('http://'.$centralHost.'/admin/login', [
        'email' => $tenantEmail,
        'password' => $tenantPassword,
    ])->assertStatus(302)->assertSessionHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();

    auth()->guard('admin')->logout();
    tenancy()->end();

    // --- The platform admin's creds are REJECTED at tenant A's web login ------
    $this->post('http://'.$tenantHost.'/login', [
        'email' => $adminEmail,
        'password' => $adminPassword,
    ])->assertStatus(302)->assertSessionHasErrors('email');

    expect(auth()->guard('web')->check())->toBeFalse();

    auth()->guard('web')->logout();
    tenancy()->end();

    // --- Sanity: each identity DOES authenticate against its OWN store ---------
    $this->post('http://'.$centralHost.'/admin/login', [
        'email' => $adminEmail,
        'password' => $adminPassword,
    ])->assertStatus(302)->assertSessionHasNoErrors();
    expect(auth()->guard('admin')->check())->toBeTrue();

    auth()->guard('admin')->logout();
    tenancy()->end();

    $this->post('http://'.$tenantHost.'/login', [
        'email' => $tenantEmail,
        'password' => $tenantPassword,
    ])->assertStatus(302)->assertSessionHasNoErrors();
    expect(auth()->guard('web')->check())->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
