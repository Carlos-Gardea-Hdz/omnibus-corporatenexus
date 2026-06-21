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
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Member CRUD in tenant context (CONTRACT §5/§6, test list 7–12).
 *
 *  - invite under limit creates a hashed-password user with the chosen role;
 *  - seat-limit rejects an invite past TenantPlan::seatLimit() (0 = unlimited);
 *  - role gating: a `member` POSTing store/update/destroy gets 403; an admin
 *    cannot assign/target `owner`;
 *  - owner-singleton: the last owner cannot be removed or demoted; an owner
 *    transfer demotes the previous owner in the SAME transaction;
 *  - self-removal is rejected.
 *
 * All web validation/domain-rule rejections are 302 + session error, NEVER 422
 * and NEVER 500. Real PostgreSQL 18, no RefreshDatabase.
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

function provisionMemberTenant(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Business): Tenant
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

    // Synchronously provisioned → mark Active so EnsureTenantIsActive serves it.
    return markTenantActive($tenant);
}

function memberHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('invites a member under the seat limit: a new row with the chosen role + hashed password, 302 + success', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Invite Co', 'inviteco');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@inviteco.test']));
    $host = memberHost($tenant);

    $response = $this->actingAs($owner)
        ->post('http://'.$host.'/members', [
            'name' => 'New Hire',
            'email' => 'newhire@inviteco.test',
            'role' => MemberRole::Admin->value,
        ]);

    $response->assertStatus(302)->assertSessionHasNoErrors();

    /** @var array{count:int, role:string|null, password:string|null} $row */
    $row = $tenant->run(function (): array {
        $u = User::query()->where('email', 'newhire@inviteco.test')->first();

        return [
            'count' => User::query()->count(),
            'role' => $u?->role->value,
            'password' => $u?->getAttribute('password'),
        ];
    });

    expect($row['count'])->toBe(2)
        ->and($row['role'])->toBe(MemberRole::Admin->value)
        // Hashed, never plaintext.
        ->and(str_starts_with((string) $row['password'], '$'))->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects an invite past the plan seat limit (302 + error, 0 new rows); unlimited never trips', function () use (&$provisioned): void {
    // Free plan = 3 seats.
    $tenant = provisionMemberTenant('Seat Co', 'seatco', TenantPlan::Free);
    $provisioned = [$tenant];

    $owner = $tenant->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@seatco.test']);
        // Fill to the seat limit (owner + 2 = 3).
        User::factory()->count(2)->create();

        return $owner;
    });

    expect($tenant->run(fn (): int => User::query()->count()))->toBe(3);

    $host = memberHost($tenant);

    $this->actingAs($owner)
        ->post('http://'.$host.'/members', [
            'name' => 'Over Limit',
            'email' => 'over@seatco.test',
            'role' => MemberRole::Member->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors();

    // No new row was written.
    expect($tenant->run(fn (): int => User::query()->count()))->toBe(3)
        ->and($tenant->run(fn (): int => User::query()->where('email', 'over@seatco.test')->count()))->toBe(0);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('never trips the seat limit on an unlimited plan (seatLimit()==0)', function () use (&$provisioned): void {
    // Enterprise plan = 0 = unlimited.
    $tenant = provisionMemberTenant('Unlimited Co', 'unlimitedco', TenantPlan::Enterprise);
    $provisioned = [$tenant];

    $owner = $tenant->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@unlimitedco.test']);
        User::factory()->count(200)->create();

        return $owner;
    });

    $host = memberHost($tenant);

    $this->actingAs($owner)
        ->post('http://'.$host.'/members', [
            'name' => 'Yet Another',
            'email' => 'another@unlimitedco.test',
            'role' => MemberRole::Member->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): int => User::query()->where('email', 'another@unlimitedco.test')->count()))->toBe(1);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('forbids a plain member from managing members (store/update/destroy → 403)', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Gate Member Co', 'gatemember');
    $provisioned = [$tenant];

    [$member, $victim] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@gatemember.test']);
        $member = User::factory()->create(['email' => 'plain@gatemember.test', 'role' => MemberRole::Member]);
        $victim = User::factory()->create(['email' => 'victim@gatemember.test', 'role' => MemberRole::Member]);

        return [$member, $victim];
    });

    $host = memberHost($tenant);

    $this->actingAs($member)
        ->post('http://'.$host.'/members', [
            'name' => 'Sneaky', 'email' => 'sneaky@gatemember.test', 'role' => MemberRole::Member->value,
        ])->assertStatus(403);

    $this->actingAs($member)
        ->patch('http://'.$host.'/members/'.$victim->getKey(), ['role' => MemberRole::Admin->value])
        ->assertStatus(403);

    $this->actingAs($member)
        ->delete('http://'.$host.'/members/'.$victim->getKey())
        ->assertStatus(403);

    // Nothing changed.
    expect($tenant->run(fn (): int => User::query()->count()))->toBe(3)
        ->and($tenant->run(fn (): string => User::query()->whereKey($victim->getKey())->value('role')?->value))
        ->toBe(MemberRole::Member->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('forbids an admin from creating or promoting to owner (302 + error)', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Admin Limit Co', 'adminlimit');
    $provisioned = [$tenant];

    [$admin, $target] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@adminlimit.test']);
        $admin = User::factory()->admin()->create(['email' => 'admin@adminlimit.test']);
        $target = User::factory()->create(['email' => 'target@adminlimit.test', 'role' => MemberRole::Member]);

        return [$admin, $target];
    });

    $host = memberHost($tenant);

    // Admin inviting an owner → rejected.
    $this->actingAs($admin)
        ->post('http://'.$host.'/members', [
            'name' => 'Would-be Owner', 'email' => 'wbo@adminlimit.test', 'role' => MemberRole::Owner->value,
        ])->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->run(fn (): int => User::query()->where('email', 'wbo@adminlimit.test')->count()))->toBe(0);

    // Admin promoting a member to owner → rejected.
    $this->actingAs($admin)
        ->patch('http://'.$host.'/members/'.$target->getKey(), ['role' => MemberRole::Owner->value])
        ->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->run(fn (): string => User::query()->whereKey($target->getKey())->value('role')?->value))
        ->toBe(MemberRole::Member->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('protects the sole owner from removal (302 + error, owner remains)', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Sole Owner Co', 'soleowner');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@soleowner.test']));
    $host = memberHost($tenant);

    // The owner cannot remove themselves (self-removal AND sole-owner both apply).
    $this->actingAs($owner)
        ->delete('http://'.$host.'/members/'.$owner->getKey())
        ->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->run(fn (): int => User::query()->where('role', MemberRole::Owner->value)->count()))->toBe(1)
        ->and($tenant->run(fn (): bool => User::query()->whereKey($owner->getKey())->exists()))->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('blocks demoting the sole owner via plain update but allows a full owner transfer (one owner, prev → admin, same tx)', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Transfer Co', 'transferco');
    $provisioned = [$tenant];

    [$owner, $successor] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@transferco.test']);
        $successor = User::factory()->admin()->create(['email' => 'successor@transferco.test']);

        return [$owner, $successor];
    });

    $host = memberHost($tenant);

    // Demoting the owner directly to admin is rejected (owner-singleton guard).
    $this->actingAs($owner)
        ->patch('http://'.$host.'/members/'.$owner->getKey(), ['role' => MemberRole::Admin->value])
        ->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->run(fn (): string => User::query()->whereKey($owner->getKey())->value('role')?->value))
        ->toBe(MemberRole::Owner->value);

    // A full transfer: promote the successor to owner. The previous owner is
    // demoted to admin in the same transaction; exactly one owner remains.
    $this->actingAs($owner)
        ->patch('http://'.$host.'/members/'.$successor->getKey(), ['role' => MemberRole::Owner->value])
        ->assertStatus(302)->assertSessionHasNoErrors();

    /** @var array{owners:int, prev:string, next:string} $state */
    $state = $tenant->run(fn (): array => [
        'owners' => User::query()->where('role', MemberRole::Owner->value)->count(),
        'prev' => User::query()->whereKey($owner->getKey())->value('role')?->value,
        'next' => User::query()->whereKey($successor->getKey())->value('role')?->value,
    ]);

    expect($state['owners'])->toBe(1)
        ->and($state['prev'])->toBe(MemberRole::Admin->value)
        ->and($state['next'])->toBe(MemberRole::Owner->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects self-removal (302 + error, the actor remains)', function () use (&$provisioned): void {
    $tenant = provisionMemberTenant('Self Remove Co', 'selfremove');
    $provisioned = [$tenant];

    [$owner, $admin] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@selfremove.test']);
        $admin = User::factory()->admin()->create(['email' => 'admin@selfremove.test']);

        return [$owner, $admin];
    });

    $host = memberHost($tenant);

    // An admin (not the sole owner) still cannot delete their own record.
    $this->actingAs($admin)
        ->delete('http://'.$host.'/members/'.$admin->getKey())
        ->assertStatus(302)->assertSessionHasErrors();

    expect($tenant->run(fn (): bool => User::query()->whereKey($admin->getKey())->exists()))->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
