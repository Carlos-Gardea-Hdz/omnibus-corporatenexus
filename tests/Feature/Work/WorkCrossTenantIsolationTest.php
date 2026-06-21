<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Cross-tenant isolation for the Work domain (projects + tasks) — the #1
 * multitenancy law applied to the new slice. Two tenants get their own physical
 * databases; a project + task written in tenant A's context must be neither
 * readable, updatable, nor deletable from tenant B — through BOTH the Eloquent
 * path AND a raw DB query — and the two tenants must resolve to DISTINCT
 * databases. After B's attempts, A's rows are still intact and untouched.
 *
 * This mirrors (and strengthens) the foundation CrossTenantIsolationTest, whose
 * Note blocks are gone with the Note model: projects/tasks now carry the proof.
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE is forbidden inside a
 * transaction).
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
    cleanCentralRegistry();
    $provisioned = [];
});

function provisionWorkIsolationTenant(string $name, string $subdomain): Tenant
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

it('isolates projects across tenants (Eloquent + raw; distinct DBs; A intact)', function () use (&$provisioned): void {
    $tenantA = provisionWorkIsolationTenant('Work Iso A', 'workisoa');
    $tenantB = provisionWorkIsolationTenant('Work Iso B', 'workisob');
    $provisioned = [$tenantA, $tenantB];

    // Distinct physical databases.
    $dbA = $tenantA->database()->getName();
    $dbB = $tenantB->database()->getName();
    expect($dbA)->not->toBe($dbB)
        ->and($dbA)->toStartWith('tenant_')
        ->and($dbB)->toStartWith('tenant_');

    // --- Write a project inside tenant A's context -----------------------
    tenancy()->initialize($tenantA);
    $project = Project::create([
        'name' => 'A-secret Project',
        'description' => 'only-A-should-see-this',
        'status' => ProjectStatus::Active,
    ]);
    $projectId = $project->getKey();

    // A reads its own row (Eloquent + raw).
    expect(Project::query()->count())->toBe(1)
        ->and(Project::find($projectId))->not->toBeNull();
    $rawA = DB::select('select count(*) as c from projects where name = ?', ['A-secret Project']);
    expect((int) $rawA[0]->c)->toBe(1);
    tenancy()->end();

    // --- Tenant B sees NOTHING of A's project ----------------------------
    tenancy()->initialize($tenantB);

    // READ — Eloquent + raw: zero rows, A's id unreachable.
    expect(Project::query()->count())->toBe(0)
        ->and(Project::find($projectId))->toBeNull();
    $rawB = DB::select('select count(*) as c from projects where name = ?', ['A-secret Project']);
    expect((int) $rawB[0]->c)->toBe(0);

    // UPDATE — Eloquent + raw affect zero rows.
    expect(Project::query()->where('name', 'A-secret Project')->update(['description' => 'pwned']))->toBe(0)
        ->and(DB::update('update projects set description = ? where name = ?', ['pwned', 'A-secret Project']))->toBe(0);

    // DELETE — Eloquent + raw affect zero rows.
    expect(Project::query()->where('name', 'A-secret Project')->delete())->toBe(0)
        ->and(DB::delete('delete from projects where name = ?', ['A-secret Project']))->toBe(0);
    tenancy()->end();

    // --- Back in A: the project is intact and untouched by B -------------
    tenancy()->initialize($tenantA);
    $reread = Project::find($projectId);
    expect($reread)->not->toBeNull()
        ->and($reread->description)->toBe('only-A-should-see-this');
    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('isolates tasks across tenants (Eloquent + raw; A intact)', function () use (&$provisioned): void {
    $tenantA = provisionWorkIsolationTenant('Task Iso A', 'taskisoa');
    $tenantB = provisionWorkIsolationTenant('Task Iso B', 'taskisob');
    $provisioned = [$tenantA, $tenantB];

    // --- Write a project + task inside tenant A's context ----------------
    tenancy()->initialize($tenantA);
    $project = Project::create([
        'name' => 'A Project',
        'status' => ProjectStatus::Active,
    ]);
    $task = $project->tasks()->create([
        'title' => 'A-secret Task',
        'description' => 'only-A',
        'status' => TaskStatus::Todo,
    ]);
    $taskId = $task->getKey();

    expect(Task::query()->where('title', 'A-secret Task')->count())->toBe(1);
    $rawA = DB::select('select count(*) as c from tasks where title = ?', ['A-secret Task']);
    expect((int) $rawA[0]->c)->toBe(1);
    tenancy()->end();

    // --- Tenant B sees NOTHING of A's task -------------------------------
    tenancy()->initialize($tenantB);

    expect(Task::query()->where('title', 'A-secret Task')->count())->toBe(0)
        ->and(Task::find($taskId))->toBeNull();
    $rawB = DB::select('select count(*) as c from tasks where title = ?', ['A-secret Task']);
    expect((int) $rawB[0]->c)->toBe(0);

    // UPDATE — Eloquent + raw affect zero rows.
    expect(Task::query()->where('title', 'A-secret Task')->update(['description' => 'pwned']))->toBe(0)
        ->and(DB::update('update tasks set description = ? where title = ?', ['pwned', 'A-secret Task']))->toBe(0);

    // DELETE — Eloquent + raw affect zero rows.
    expect(Task::query()->where('title', 'A-secret Task')->delete())->toBe(0)
        ->and(DB::delete('delete from tasks where title = ?', ['A-secret Task']))->toBe(0);
    tenancy()->end();

    // --- Back in A: the task is intact and untouched by B ----------------
    tenancy()->initialize($tenantA);
    $reread = Task::find($taskId);
    expect($reread)->not->toBeNull()
        ->and($reread->description)->toBe('only-A');
    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('keeps the assignee guard tenant-local: A user ids are unknown in B', function () use (&$provisioned): void {
    $tenantA = provisionWorkIsolationTenant('Member Iso A', 'memisoa');
    $tenantB = provisionWorkIsolationTenant('Member Iso B', 'memisob');
    $provisioned = [$tenantA, $tenantB];

    // A member created in A must not exist in B's users table — the very basis
    // of the assignment member-guard (exists('users','id') is per-connection).
    $aUserId = $tenantA->run(fn (): int => User::factory()->create(['email' => 'amember@memisoa.test'])->getKey());

    expect($tenantB->run(fn (): bool => User::query()->whereKey($aUserId)->exists()))->toBeFalse();
    expect($tenantA->run(fn (): bool => User::query()->whereKey($aUserId)->exists()))->toBeTrue();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
