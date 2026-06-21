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
 * THE crown of slice 002 (CONTRACT test list 5): cross-tenant AUTH isolation.
 *
 * A user U exists ONLY in tenant A's physical database. The IDENTICAL
 * credentials {U.email, P} authenticate on A's host but are rejected on B's
 * host — not by a check, but because U is physically absent from B's `users`
 * table (the per-tenant DB IS the isolation). A user of A can NEVER auth into B.
 *
 * Real PostgreSQL 18, no RefreshDatabase — see the foundation isolation suite.
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

function provisionAuthIsolationTenant(string $name, string $subdomain): Tenant
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

    return $tenant;
}

it('lets a tenant-A user log in on A but rejects the IDENTICAL credentials on B', function () use (&$provisioned): void {
    $tenantA = provisionAuthIsolationTenant('Alpha Org', 'alphaauth');
    $tenantB = provisionAuthIsolationTenant('Bravo Org', 'bravoauth');
    $provisioned = [$tenantA, $tenantB];

    // Distinct physical databases (the substrate of the isolation).
    expect($tenantA->database()->getName())->not->toBe($tenantB->database()->getName());

    // Seed user U ONLY in tenant A.
    $email = 'shared.identity@alphaauth.test';
    $password = 'Sup3r-Secret-Pass!';

    $tenantA->run(function () use ($email, $password): void {
        User::factory()->owner()->create(['email' => $email, 'password' => $password]);
    });

    // U is physically present in A, absent from B (Eloquent + raw).
    expect($tenantA->run(fn (): int => User::query()->where('email', $email)->count()))->toBe(1)
        ->and($tenantB->run(fn (): int => User::query()->where('email', $email)->count()))->toBe(0);

    $rawB = $tenantB->run(fn (): array => DB::select('select count(*) as c from users where email = ?', [$email]));
    expect((int) $rawB[0]->c)->toBe(0);

    $hostA = (string) $tenantA->domains()->value('domain');
    $hostB = (string) $tenantB->domains()->value('domain');

    // --- On A: the credentials authenticate -------------------------------
    $this->post('http://'.$hostA.'/login', ['email' => $email, 'password' => $password])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect(auth()->guard('web')->check())->toBeTrue();

    // Fully reset auth/tenant state between the two host hits.
    auth()->guard('web')->logout();
    tenancy()->end();

    // --- On B: the IDENTICAL credentials are rejected (U absent) ----------
    $this->post('http://'.$hostB.'/login', ['email' => $email, 'password' => $password])
        ->assertStatus(302)
        ->assertSessionHasErrors('email');

    expect(auth()->guard('web')->check())->toBeFalse();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
