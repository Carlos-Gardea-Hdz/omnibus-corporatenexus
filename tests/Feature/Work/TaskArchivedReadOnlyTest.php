<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Archived-project read-only — the two UNTESTED of four guard sites
 * (ProjectArchivedException). The exception is enforced in CreateTask::30,
 * TransitionTask::23, UpdateTask::22 and AssignTask::25, but only the create and
 * transition sites were covered (TaskCrudTest, TaskTransitionTest). These tests
 * close the UPDATE and ASSIGN gaps:
 *
 *  - PATCH a task UPDATE on an ARCHIVED project → 302 + flash `error`, NEVER
 *    500, and the task's title/priority are UNCHANGED;
 *  - PATCH a task ASSIGN on an ARCHIVED project → 302 + flash `error`, NEVER
 *    500, and the task's assigned_to is UNCHANGED.
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

function provisionArchivedReadOnlyTenant(string $name, string $subdomain): Tenant
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

function archivedReadOnlyHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('gracefully rejects UPDATING a task on an archived project (302 + flash, NEVER 500, title/priority unchanged)', function () use (&$provisioned): void {
    $tenant = provisionArchivedReadOnlyTenant('Task Archived Update Co', 'taskarchupd');
    $provisioned = [$tenant];

    [$member, $projectId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@taskarchupd.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $task = Task::factory()->for($project)->create([
            'title' => 'Frozen title',
            'priority' => TaskPriority::Low,
        ]);

        return [$member, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.archivedReadOnlyHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId, [
            'title' => 'Should not stick',
            'description' => 'Should not stick either.',
            'priority' => TaskPriority::Urgent->value,
        ])
        // ProjectArchivedException → render handler → 302 + flash, not 500.
        ->assertStatus(302)
        ->assertSessionHas('error');

    /** @var array{title:string|null, priority:string|null} $row */
    $row = $tenant->run(fn (): array => [
        'title' => Task::query()->whereKey($taskId)->value('title'),
        'priority' => Task::query()->whereKey($taskId)->value('priority')?->value,
    ]);

    // Read-only held: the archived task kept its original title + priority.
    expect($row['title'])->toBe('Frozen title')
        ->and($row['priority'])->toBe(TaskPriority::Low->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('gracefully rejects ASSIGNING a task on an archived project (302 + flash, NEVER 500, assignee unchanged)', function () use (&$provisioned): void {
    $tenant = provisionArchivedReadOnlyTenant('Task Archived Assign Co', 'taskarchasg');
    $provisioned = [$tenant];

    [$member, $otherId, $projectId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@taskarchasg.test', 'role' => MemberRole::Member]);
        $other = User::factory()->create(['email' => 'other@taskarchasg.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        // Pre-assigned to $member; the archived guard must keep it that way.
        $task = Task::factory()->for($project)->create(['assigned_to' => $member->getKey()]);

        return [$member, $other->getKey(), $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.archivedReadOnlyHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/assignee', [
            // A real member of this tenant — so the only thing rejecting the
            // write is the archived guard, not the assignee member-guard.
            'assigned_to' => $otherId,
        ])
        // ProjectArchivedException → render handler → 302 + flash, not 500.
        ->assertStatus(302)
        ->assertSessionHas('error');

    // Read-only held: the archived task kept its original assignee.
    expect($tenant->run(fn (): ?int => Task::query()->whereKey($taskId)->value('assigned_to')))
        ->toBe($member->getKey());

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
