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
 * Inertia prop CONTRACTS for the three tenant pages (CONTRACT §9, test list 14).
 * Props are snake_case; enum props serialize to their string value. Crucially,
 * NO password / remember_token ever reaches a persisted prop.
 *
 * These need tenant context (tenant users + tenant() resolution), so they run
 * on real PostgreSQL with provisioned tenants — no RefreshDatabase.
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

function provisionPropTenant(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Free): Tenant
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

function propHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('Auth/Login exposes only the tenant summary prop (no user data)', function () use (&$provisioned): void {
    $tenant = provisionPropTenant('Login Page Co', 'loginpage');
    $provisioned = [$tenant];

    $this->get('http://'.propHost($tenant).route('tenant.login', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/Login')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.status', $tenant->status->value)
            ->where('tenant.plan', $tenant->plan->value)
            ->has('tenant.name')
            ->missing('password')
            ->missing('remember_token')
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('Tenant/Dashboard exposes tenant, auth_user, member_count and seat_limit (no secrets)', function () use (&$provisioned): void {
    $tenant = provisionPropTenant('Dash Co', 'dashco');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@dashco.test', 'name' => 'Dash Owner']));

    $this->actingAs($owner)
        ->get('http://'.propHost($tenant).route('tenant.dashboard', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Dashboard')
            ->where('tenant.id', $tenant->id)
            ->where('auth_user.id', $owner->getKey())
            ->where('auth_user.name', 'Dash Owner')
            ->where('auth_user.email', 'owner@dashco.test')
            ->where('auth_user.role', MemberRole::Owner->value)
            ->where('member_count', 1)
            ->where('seat_limit', TenantPlan::Free->seatLimit())
            ->missing('auth_user.password')
            ->missing('auth_user.remember_token')
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('Tenant/Members/Index exposes the exact member-list contract with seats and capabilities', function () use (&$provisioned): void {
    $tenant = provisionPropTenant('Members Page Co', 'memberspage', TenantPlan::Free);
    $provisioned = [$tenant];

    $owner = $tenant->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@memberspage.test']);
        User::factory()->create(['email' => 'member@memberspage.test', 'role' => MemberRole::Member]);

        return $owner;
    });

    $this->actingAs($owner)
        ->get('http://'.propHost($tenant).route('tenant.members.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Members/Index')
            ->has('members', 2, fn ($member) => $member
                ->has('id')
                ->has('name')
                ->has('email')
                ->has('role')
                ->has('role_label')
                ->has('is_self')
                ->has('is_owner')
                ->missing('password')
                ->missing('remember_token')
                ->etc()
            )
            ->where('seat_limit', TenantPlan::Free->seatLimit())
            ->where('seat_used', 2)
            ->where('seats_remaining', TenantPlan::Free->seatLimit() - 2)
            ->has('assignable_roles')
            ->where('can.manage_members', true)
            ->where('can.invite', true)
            ->has('can.transfer_ownership')
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('marks the acting owner as is_self and is_owner in the member list', function () use (&$provisioned): void {
    $tenant = provisionPropTenant('Self Flag Co', 'selfflag');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@selfflag.test']));

    $this->actingAs($owner)
        ->get('http://'.propHost($tenant).route('tenant.members.index', absolute: false))
        ->assertOk()
        ->assertInertia(function ($page) use ($owner): void {
            /** @var array<int, array<string, mixed>> $members */
            $members = $page->toArray()['props']['members'];
            $self = collect($members)->firstWhere('id', $owner->getKey());

            expect($self)->not->toBeNull()
                ->and($self['is_self'])->toBeTrue()
                ->and($self['is_owner'])->toBeTrue()
                ->and($self['role'])->toBe(MemberRole::Owner->value);
        });

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
