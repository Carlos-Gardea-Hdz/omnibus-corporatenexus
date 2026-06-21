<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Task CRUD in tenant context (CONTRACT §C6 CreateTask/UpdateTask, §C7
 * TaskController). Tasks are managed by ANY member (no project-manage gate):
 *
 *  - a plain member creates a task → 302, a `todo` row stamped with created_by;
 *  - an invalid create (title too short) → 302 + session errors, NEVER 422;
 *  - a member updates a task (title/priority) → 302, row reflects the change;
 *  - creating a task on an ARCHIVED project is gracefully rejected
 *    (ProjectArchivedException → 302 + flash error, NEVER 500, no row written).
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

function provisionTaskTenant(string $name, string $subdomain): Tenant
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

function taskHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('lets ANY member create a task: 302, a todo row stamped with created_by', function () use (&$provisioned): void {
    $tenant = provisionTaskTenant('Task Create Co', 'taskcreate');
    $provisioned = [$tenant];

    [$member, $projectId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@taskcreate.test']);
        $member = User::factory()->create(['email' => 'member@taskcreate.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        return [$member, $project->getKey()];
    });

    $this->actingAs($member)
        ->post('http://'.taskHost($tenant).'/projects/'.$projectId.'/tasks', [
            'title' => 'Write the onboarding doc',
            'description' => 'Cover the first-run experience.',
            'priority' => TaskPriority::High->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    /** @var array{count:int, status:string|null, priority:string|null, created_by:int|null} $row */
    $row = $tenant->run(function (): array {
        $task = Task::query()->where('title', 'Write the onboarding doc')->first();

        return [
            'count' => Task::query()->count(),
            'status' => $task?->status->value,
            'priority' => $task?->priority->value,
            'created_by' => $task?->created_by,
        ];
    });

    expect($row['count'])->toBe(1)
        ->and($row['status'])->toBe(TaskStatus::Todo->value)
        ->and($row['priority'])->toBe(TaskPriority::High->value)
        ->and($row['created_by'])->toBe($member->getKey());

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects an invalid task create with 302 + session errors, NEVER 422', function () use (&$provisioned): void {
    $tenant = provisionTaskTenant('Task Invalid Co', 'taskinvalid');
    $provisioned = [$tenant];

    [$member, $projectId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@taskinvalid.test']);
        $member = User::factory()->create(['email' => 'member@taskinvalid.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        return [$member, $project->getKey()];
    });

    // Title below Min(3) → graceful web rejection.
    $this->actingAs($member)
        ->post('http://'.taskHost($tenant).'/projects/'.$projectId.'/tasks', [
            'title' => 'no',
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors('title');

    expect($tenant->run(fn (): int => Task::query()->count()))->toBe(0);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('lets a member update a task (302, the row reflects the new title/priority)', function () use (&$provisioned): void {
    $tenant = provisionTaskTenant('Task Update Co', 'taskupdate');
    $provisioned = [$tenant];

    [$member, $projectId, $taskId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@taskupdate.test']);
        $member = User::factory()->create(['email' => 'member@taskupdate.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create([
            'title' => 'Before title',
            'priority' => TaskPriority::Low,
        ]);

        return [$member, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.taskHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId, [
            'title' => 'After title',
            'description' => 'Updated.',
            'priority' => TaskPriority::Urgent->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    /** @var array{title:string|null, priority:string|null} $row */
    $row = $tenant->run(fn (): array => [
        'title' => Task::query()->whereKey($taskId)->value('title'),
        'priority' => Task::query()->whereKey($taskId)->value('priority')?->value,
    ]);

    expect($row['title'])->toBe('After title')
        ->and($row['priority'])->toBe(TaskPriority::Urgent->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('gracefully rejects creating a task on an ARCHIVED project (302 + flash, NEVER 500, no row)', function () use (&$provisioned): void {
    $tenant = provisionTaskTenant('Task Archived Co', 'taskarchived');
    $provisioned = [$tenant];

    [$member, $projectId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@taskarchived.test']);
        $member = User::factory()->create(['email' => 'member@taskarchived.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);

        return [$member, $project->getKey()];
    });

    $this->actingAs($member)
        ->post('http://'.taskHost($tenant).'/projects/'.$projectId.'/tasks', [
            'title' => 'Should not be created',
            'priority' => TaskPriority::Medium->value,
        ])
        // ProjectArchivedException → render handler → 302 + flash, not 500.
        ->assertStatus(302)
        ->assertSessionHas('error');

    expect($tenant->run(fn (): int => Task::query()->count()))->toBe(0);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
