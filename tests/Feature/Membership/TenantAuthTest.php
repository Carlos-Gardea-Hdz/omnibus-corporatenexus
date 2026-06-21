<?php

declare(strict_types=1);

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
 * Tenant-side authentication (CONTRACT §6 LoginController, test list 1–4).
 *
 * Auth happens AFTER tenant identification, on the tenant subdomain: once
 * InitializeTenancyByDomain swaps the default connection to the tenant DB, the
 * default `web` guard authenticates against the TENANT `users` table — no new
 * guard. A guest hitting a tenant-auth route is redirected to the tenant login
 * (risk N1: the redirect must resolve to the TENANT host, not central).
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE is forbidden inside a
 * transaction) — mirrors the foundation CrossTenantIsolationTest provisioning
 * approach: clean the central registry by hand and drop every tenant DB created.
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

/** Provision a tenant end-to-end on real Postgres (DB + tenant migrations). */
function provisionAuthTenant(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Business): Tenant
{
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: $name,
        subdomain: $subdomain,
        ownerEmail: "owner@{$subdomain}.test",
        plan: $plan,
    ));

    (new CreateDatabase($tenant))->handle(app(DatabaseManager::class));
    tenancy()->initialize($tenant);
    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--realpath' => false,
        '--force' => true,
    ]);
    tenancy()->end();

    return $tenant;
}

/** Host (subdomain.central) the tenant identifies on. */
function authHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('logs a tenant user in and redirects to the dashboard (302, authenticated)', function () use (&$provisioned): void {
    $tenant = provisionAuthTenant('Auth Co', 'authco');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        User::factory()->owner()->create([
            'email' => 'jordan@authco.test',
            'password' => 'correct-horse-battery',
        ]);
    });

    $host = authHost($tenant);

    $response = $this->post('http://'.$host.'/login', [
        'email' => 'jordan@authco.test',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertStatus(302)
        ->assertSessionHasNoErrors()
        ->assertRedirect('http://'.$host.route('tenant.dashboard', absolute: false));

    expect(auth()->guard('web')->check())->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects bad credentials with 302 + session error and stays a guest (NEVER 422)', function () use (&$provisioned): void {
    $tenant = provisionAuthTenant('Bad Creds Co', 'badcreds');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        User::factory()->owner()->create([
            'email' => 'real@badcreds.test',
            'password' => 'the-real-password',
        ]);
    });

    $host = authHost($tenant);

    $response = $this->post('http://'.$host.'/login', [
        'email' => 'real@badcreds.test',
        'password' => 'WRONG-password',
    ]);

    // Web validation = 302 + session error, never a 422 JSON body.
    $response->assertStatus(302)
        ->assertSessionHasErrors('email');

    expect(auth()->guard('web')->check())->toBeFalse();

    // A non-existent email is rejected the same way (non-enumerating).
    $this->post('http://'.$host.'/login', [
        'email' => 'ghost@badcreds.test',
        'password' => 'anything',
    ])->assertStatus(302)->assertSessionHasErrors('email');

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('guards tenant routes: a guest is redirected to the tenant login, an authed user gets 200', function () use (&$provisioned): void {
    $tenant = provisionAuthTenant('Gate Co', 'gateco');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        User::factory()->owner()->create(['email' => 'boss@gateco.test', 'password' => 'open-sesame']);
    });

    $host = authHost($tenant);
    $loginUrl = 'http://'.$host.route('tenant.login', absolute: false);

    // Guest → protected routes redirect to the TENANT login (risk N1: tenant host).
    $this->get('http://'.$host.'/dashboard')
        ->assertStatus(302)
        ->assertRedirect($loginUrl);

    $this->get('http://'.$host.'/members')
        ->assertStatus(302)
        ->assertRedirect($loginUrl);

    // Authenticated → 200.
    $owner = $tenant->run(fn (): User => User::query()->firstOrFail());

    $this->actingAs($owner)
        ->get('http://'.$host.'/dashboard')
        ->assertOk();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('logs out: 302 to login and the session is no longer authenticated', function () use (&$provisioned): void {
    $tenant = provisionAuthTenant('Logout Co', 'logoutco');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        User::factory()->owner()->create(['email' => 'bye@logoutco.test', 'password' => 'see-you-later']);
    });

    $host = authHost($tenant);
    $owner = $tenant->run(fn (): User => User::query()->firstOrFail());

    $response = $this->actingAs($owner)
        ->post('http://'.$host.'/logout');

    $response->assertStatus(302)
        ->assertRedirect('http://'.$host.route('tenant.login', absolute: false));

    expect(auth()->guard('web')->check())->toBeFalse();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
