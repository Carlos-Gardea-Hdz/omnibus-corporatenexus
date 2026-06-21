<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/*
| Owner-singleton DB invariant (W2). A PARTIAL UNIQUE INDEX
| `users_single_owner ON users (role) WHERE role = 'owner'` makes "at most one
| owner" a database-enforced truth — no future code path can ever produce two
| owners in a tenant. Exercised on a real provisioned tenant DB.
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

function provisionIndexTenant(string $name, string $subdomain): Tenant
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

it('rejects a second owner at the database level (partial unique index)', function () use (&$provisioned): void {
    $tenant = provisionIndexTenant('Single Owner Index Co', 'singleownerix');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        User::factory()->owner()->create(['email' => 'owner@singleownerix.test']);

        // A second owner row must violate the partial unique index.
        expect(fn (): User => User::factory()->owner()->create(['email' => 'usurper@singleownerix.test']))
            ->toThrow(QueryException::class);

        // Exactly one owner survives.
        expect(User::query()->where('role', MemberRole::Owner->value)->count())->toBe(1);
    });

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('allows the owner-transfer swap under the index (demote-then-promote, exactly one owner)', function () use (&$provisioned): void {
    $tenant = provisionIndexTenant('Transfer Index Co', 'transferix');
    $provisioned = [$tenant];

    [$owner, $successor] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@transferix.test']);
        $successor = User::factory()->admin()->create(['email' => 'successor@transferix.test']);

        return [$owner, $successor];
    });

    $host = (string) $tenant->domains()->value('domain');

    // The transfer demotes the current owner BEFORE promoting the successor, each
    // its own statement, so the partial unique index is never violated mid-tx.
    $this->actingAs($owner)
        ->patch('http://'.$host.'/members/'.$successor->getKey(), ['role' => MemberRole::Owner->value])
        ->assertStatus(302)->assertSessionHasNoErrors();

    expect($tenant->run(fn (): int => User::query()->where('role', MemberRole::Owner->value)->count()))->toBe(1)
        ->and($tenant->run(fn (): string => User::query()->whereKey($successor->getKey())->value('role')?->value))
        ->toBe(MemberRole::Owner->value)
        ->and($tenant->run(fn (): string => User::query()->whereKey($owner->getKey())->value('role')?->value))
        ->toBe(MemberRole::Admin->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
