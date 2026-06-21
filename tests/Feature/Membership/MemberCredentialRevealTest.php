<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/*
| One-shot invited-member credential reveal (F3). Inviting a member generates a
| one-time temp password; the inviter must SEE it once so the new member can log
| in (email delivery deferred). It travels as a transient `flash.temp_password`
| (forwarded by HandleInertiaRequests) — NEVER on any list prop, never logged.
|
| Two facts proven here:
|   1. the invite redirect carries the temp password as a one-shot flash;
|   2. a normal members-list render carries NO temp_password.
|
| Needs tenant context (tenant users + tenant() resolution) → real PostgreSQL,
| no RefreshDatabase.
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

function provisionCredentialTenant(string $name, string $subdomain): Tenant
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

function credentialHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('flashes the invited member temp password once so the inviter can reveal it', function () use (&$provisioned): void {
    $tenant = provisionCredentialTenant('Credential Co', 'credco');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@credco.test']));
    $host = credentialHost($tenant);

    $response = $this->actingAs($owner)
        ->post('http://'.$host.'/members', [
            'name' => 'New Hire',
            'email' => 'newhire@credco.test',
            'role' => MemberRole::Member->value,
        ]);

    $response->assertStatus(302)->assertSessionHasNoErrors();

    // The one-shot reveal travels as a flashed session value — the exact value
    // the new member needs to log in (it matches the HASHED tenant-DB record).
    $temp = session('temp_password');
    expect($temp)->toBeString()->and($temp)->not->toBe('');

    $hashed = $tenant->run(
        fn (): string => (string) User::query()->where('email', 'newhire@credco.test')->value('password')
    );
    expect(Hash::check((string) $temp, $hashed))->toBeTrue();

    // And it surfaces on the followed members page as `flash.temp_password`
    // (the shared Inertia prop the one-time credential block reads).
    $this->actingAs($owner)
        ->withSession(['temp_password' => $temp])
        ->get('http://'.$host.route('tenant.members.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Members/Index')
            ->where('flash.temp_password', $temp));

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('never leaks a temp password on a normal members-list render', function () use (&$provisioned): void {
    $tenant = provisionCredentialTenant('No Leak Co', 'noleakco');
    $provisioned = [$tenant];

    $owner = $tenant->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@noleakco.test']);
        User::factory()->create(['email' => 'member@noleakco.test', 'role' => MemberRole::Member]);

        return $owner;
    });
    $host = credentialHost($tenant);

    // A plain list visit (no invite just happened) carries no temp password,
    // and no member row exposes a password/temp_password field.
    $this->actingAs($owner)
        ->get('http://'.$host.route('tenant.members.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Members/Index')
            ->where('flash.temp_password', null)
            ->has('members', 2, fn ($member) => $member
                ->missing('password')
                ->missing('temp_password')
                ->etc()
            ));

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
