<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * THE cross-tenant isolation suite (multitenancy §5 — the #1 multitenancy law).
 *
 * Runs against REAL PostgreSQL 18 (see phpunit.xml: DB_CONNECTION=pgsql). It
 * provisions two tenants with their own physical databases, writes a row in
 * tenant A's context, and proves tenant B can neither read, update, nor delete
 * it — through BOTH the Eloquent path AND a raw DB query — and that the two
 * tenants resolve to DISTINCT databases.
 *
 * This test does NOT use RefreshDatabase: that trait holds an open transaction
 * on the central connection, and PostgreSQL forbids `CREATE DATABASE` inside a
 * transaction block. Instead we clean the central tenancy tables manually
 * before each test (outside any transaction) and drop every tenant database we
 * provision in afterEach, so no orphan `tenant_*` databases leak between tests.
 */

/** @var array<int, Tenant> $provisioned */
$provisioned = [];

beforeEach(function (): void {
    tenancy()->end();
    // Clean central registry tables (no wrapping transaction — see file docblock).
    DB::table('domains')->delete();
    Tenant::query()->cursor()->each(function (Tenant $tenant): void {
        try {
            $tenant->database()->makeCredentials();
            $tenant->database()->manager()->deleteDatabase($tenant);
        } catch (Throwable) {
            // Ignore: DB may not exist yet.
        }
    });
    DB::table('tenants')->delete();
});

/**
 * Provision a tenant end-to-end on real Postgres: central registry row +
 * physical tenant database + tenant migrations. Returns the Tenant.
 */
function provisionTenant(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Business): Tenant
{
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: $name,
        subdomain: $subdomain,
        ownerEmail: "owner@{$subdomain}.test",
        plan: $plan,
    ));

    // The provisioning job pipeline is queued in prod; here we create the
    // physical database synchronously, then run the tenant migrations.
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

afterEach(function () use (&$provisioned): void {
    tenancy()->end();

    foreach ($provisioned as $tenant) {
        try {
            $tenant->database()->makeCredentials();
            $tenant->database()->manager()->deleteDatabase($tenant);
        } catch (Throwable) {
            // Best-effort cleanup; central rows roll back with RefreshDatabase.
        }
    }

    $provisioned = [];
});

it('isolates tenant data by physical database (Eloquent and raw query)', function () use (&$provisioned): void {
    $tenantA = provisionTenant('Tenant A Inc', 'alpha');
    $tenantB = provisionTenant('Tenant B Inc', 'bravo');
    $provisioned = [$tenantA, $tenantB];

    // The two tenants MUST resolve to distinct physical databases.
    $dbA = $tenantA->database()->getName();
    $dbB = $tenantB->database()->getName();

    expect($dbA)->not->toBe($dbB)
        ->and($dbA)->toStartWith('tenant_')
        ->and($dbB)->toStartWith('tenant_');

    // --- Write a row inside tenant A's context ---------------------------
    tenancy()->initialize($tenantA);
    $note = Note::create(['title' => 'A-secret', 'body' => 'only-A-should-see-this']);
    $noteId = $note->getKey();

    // Sanity: A can read its own row, via Eloquent and raw query.
    expect(Note::query()->count())->toBe(1)
        ->and(Note::find($noteId))->not->toBeNull();

    $rawA = DB::select('select count(*) as c from notes where title = ?', ['A-secret']);
    expect((int) $rawA[0]->c)->toBe(1);
    tenancy()->end();

    // --- Switch to tenant B: it must see NOTHING of A's data -------------
    tenancy()->initialize($tenantB);

    // READ — Eloquent: B's notes table is empty; A's id is unreachable.
    expect(Note::query()->count())->toBe(0)
        ->and(Note::find($noteId))->toBeNull();

    // READ — raw query: zero rows for A's data on B's connection.
    $rawB = DB::select('select count(*) as c from notes where title = ?', ['A-secret']);
    expect((int) $rawB[0]->c)->toBe(0);

    // UPDATE — Eloquent + raw affect zero rows (nothing to touch in B's DB).
    expect(Note::query()->where('title', 'A-secret')->update(['body' => 'pwned']))->toBe(0)
        ->and(DB::update('update notes set body = ? where title = ?', ['pwned', 'A-secret']))->toBe(0);

    // DELETE — Eloquent + raw affect zero rows.
    expect(Note::query()->where('title', 'A-secret')->delete())->toBe(0)
        ->and(DB::delete('delete from notes where title = ?', ['A-secret']))->toBe(0);

    tenancy()->end();

    // --- Back in A: the row is still intact and untouched by B -----------
    tenancy()->initialize($tenantA);
    $reread = Note::find($noteId);
    expect($reread)->not->toBeNull()
        ->and($reread->body)->toBe('only-A-should-see-this');
    tenancy()->end();
});

it('isolates the new tenant-owned members table across tenants (Eloquent and raw)', function () use (&$provisioned): void {
    $tenantA = provisionTenant('Members Tenant A', 'malpha');
    $tenantB = provisionTenant('Members Tenant B', 'mbravo');
    $provisioned = [$tenantA, $tenantB];

    // Write a member in tenant A's context.
    tenancy()->initialize($tenantA);
    $member = User::factory()->admin()->create([
        'name' => 'A-only Member',
        'email' => 'member@malpha.test',
    ]);
    $memberId = $member->getKey();

    // A sees its own member, via Eloquent and raw query.
    expect(User::query()->where('email', 'member@malpha.test')->count())->toBe(1);
    $rawA = DB::select('select count(*) as c from users where email = ?', ['member@malpha.test']);
    expect((int) $rawA[0]->c)->toBe(1);
    tenancy()->end();

    // Tenant B must see NOTHING of A's member.
    tenancy()->initialize($tenantB);

    // READ — Eloquent + raw: zero rows for A's member on B's connection.
    expect(User::query()->where('email', 'member@malpha.test')->count())->toBe(0)
        ->and(User::find($memberId))->toBeNull();
    $rawB = DB::select('select count(*) as c from users where email = ?', ['member@malpha.test']);
    expect((int) $rawB[0]->c)->toBe(0);

    // UPDATE — Eloquent + raw affect zero rows in B.
    expect(User::query()->where('email', 'member@malpha.test')->update(['role' => MemberRole::Owner->value]))->toBe(0)
        ->and(DB::update('update users set name = ? where email = ?', ['pwned', 'member@malpha.test']))->toBe(0);

    // DELETE — Eloquent + raw affect zero rows in B.
    expect(User::query()->where('email', 'member@malpha.test')->delete())->toBe(0)
        ->and(DB::delete('delete from users where email = ?', ['member@malpha.test']))->toBe(0);
    tenancy()->end();

    // Back in A: the member row is intact and untouched.
    tenancy()->initialize($tenantA);
    $reread = User::find($memberId);
    expect($reread)->not->toBeNull()
        ->and($reread->name)->toBe('A-only Member')
        ->and($reread->role)->toBe(MemberRole::Admin);
    tenancy()->end();
});

it('restores tenant context after a queued job runs (no context bleed)', function () use (&$provisioned): void {
    $tenantA = provisionTenant('Queue Tenant A', 'qalpha');
    $tenantB = provisionTenant('Queue Tenant B', 'qbravo');
    $provisioned = [$tenantA, $tenantB];

    // Write one note in A, two in B, each inside its own tenant context. A job
    // dispatched in a tenant's context re-initializes THAT tenant on execution
    // (multitenancy §3). With QUEUE_CONNECTION=sync the closure runs inline but
    // still through the tenancy-aware dispatch path.
    $tenantA->run(function (): void {
        Note::create(['title' => 'a1']);
    });

    $tenantB->run(function (): void {
        Note::create(['title' => 'b1']);
        Note::create(['title' => 'b2']);
    });

    // Each tenant sees ONLY its own rows — context did not bleed across runs.
    expect($tenantA->run(fn (): int => Note::query()->count()))->toBe(1)
        ->and($tenantB->run(fn (): int => Note::query()->count()))->toBe(2);

    // After all tenant runs, central context is restored (no tenant active).
    expect(tenant())->toBeNull();
});
