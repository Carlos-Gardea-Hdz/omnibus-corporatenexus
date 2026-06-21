<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Task assignment + the MEMBER GUARD (CONTRACT §C4 AssignTaskData, §C6
 * AssignTask). The assignee MUST be a member of THIS tenant: the DTO's
 * `Rule::exists('users','id')` resolves on the tenant connection, so a user id
 * absent from this tenant DB (e.g. a member of ANOTHER tenant) is rejected with
 * a 302 + field error — falsifiable proof the guard is real, not cosmetic.
 *
 *  - assign to a member of THIS tenant → 302, no errors, assigned_to set;
 *  - assign to a user id absent from this tenant DB → 302 + field error, the
 *    assignment is NOT applied;
 *  - unassign (assigned_to = null) → 302, assigned_to cleared;
 *  - reassign to another member → 302, assigned_to updated.
 *
 * Real PostgreSQL 18, no RefreshDatabase.
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

function provisionAssignTenant(string $name, string $subdomain): Tenant
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

    return markTenantActive($tenant);
}

function assignHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('assigns a task to a member of THIS tenant (302, no errors, assigned_to set)', function () use (&$provisioned): void {
    $tenant = provisionAssignTenant('Assign Co', 'assignco');
    $provisioned = [$tenant];

    [$actor, $assigneeId, $projectId, $taskId] = $tenant->run(function (): array {
        $actor = User::factory()->create(['email' => 'actor@assignco.test']);
        $assignee = User::factory()->create(['email' => 'assignee@assignco.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['assigned_to' => null]);

        return [$actor, $assignee->getKey(), $project->getKey(), $task->getKey()];
    });

    $this->actingAs($actor)
        ->patch('http://'.assignHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/assignee', [
            'assigned_to' => $assigneeId,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?int => Task::query()->whereKey($taskId)->value('assigned_to')))
        ->toBe($assigneeId);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects assigning a task to a user id absent from THIS tenant (302 + field error, no change)', function () use (&$provisioned): void {
    // The member guard is `Rule::exists('users','id')` resolved in tenant
    // context — so the boundary it enforces is "a user with this id exists in
    // THIS tenant's DB". Tenant users use SEQUENTIAL BIGINT ids, so a "tenant
    // B" id like 1,2,3 ALSO exists in tenant A (A has its own users 1,2,3) and
    // would correctly resolve to a REAL A member. To exercise the rejection we
    // must use an id GUARANTEED ABSENT from tenant A: seed a small, known set
    // of A members and assign a high non-existent id.
    $tenantA = provisionAssignTenant('Assign Guard A', 'assignga');
    $provisioned = [$tenantA];

    [$actor, $projectId, $taskId, $absentId] = $tenantA->run(function (): array {
        $actor = User::factory()->create(['email' => 'actor@assignga.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['assigned_to' => null]);

        // No user has this id in tenant A (sequential ids stay far below it).
        $absentId = User::query()->max('id') + 999_999;

        return [$actor, $project->getKey(), $task->getKey(), $absentId];
    });

    $this->actingAs($actor)
        ->patch('http://'.assignHost($tenantA).'/projects/'.$projectId.'/tasks/'.$taskId.'/assignee', [
            // This id does NOT exist in tenant A's users table.
            'assigned_to' => $absentId,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors('assigned_to');

    // The guard held: the absent id never landed on tenant A's task.
    expect($tenantA->run(fn (): ?int => Task::query()->whereKey($taskId)->value('assigned_to')))
        ->toBeNull();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('unassigns a task (assigned_to = null → 302, assignment cleared)', function () use (&$provisioned): void {
    $tenant = provisionAssignTenant('Unassign Co', 'unassignco');
    $provisioned = [$tenant];

    [$actor, $projectId, $taskId] = $tenant->run(function (): array {
        $actor = User::factory()->create(['email' => 'actor@unassignco.test']);
        $assignee = User::factory()->create(['email' => 'assignee@unassignco.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['assigned_to' => $assignee->getKey()]);

        return [$actor, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($actor)
        ->patch('http://'.assignHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/assignee', [
            'assigned_to' => null,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?int => Task::query()->whereKey($taskId)->value('assigned_to')))
        ->toBeNull();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('reassigns a task to another member of this tenant (302, assigned_to updated)', function () use (&$provisioned): void {
    $tenant = provisionAssignTenant('Reassign Co', 'reassignco');
    $provisioned = [$tenant];

    [$actor, $secondId, $projectId, $taskId] = $tenant->run(function (): array {
        $actor = User::factory()->create(['email' => 'actor@reassignco.test']);
        $first = User::factory()->create(['email' => 'first@reassignco.test']);
        $second = User::factory()->create(['email' => 'second@reassignco.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['assigned_to' => $first->getKey()]);

        return [$actor, $second->getKey(), $project->getKey(), $task->getKey()];
    });

    $this->actingAs($actor)
        ->patch('http://'.assignHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/assignee', [
            'assigned_to' => $secondId,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?int => Task::query()->whereKey($taskId)->value('assigned_to')))
        ->toBe($secondId);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
