<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
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
 * Inertia prop CONTRACTS for the two Work pages (CONTRACT §C9). Props are
 * snake_case; enum-derived props serialize to their string value with a
 * *_label / *_color companion. The assignable `members` list is tenant-scoped.
 * These need tenant context (tenant users + tenant() resolution), so they run on
 * real PostgreSQL with provisioned tenants — no RefreshDatabase.
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

function provisionWorkPropTenant(string $name, string $subdomain): Tenant
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

function workPropHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('Tenant/Projects/Index exposes the exact project-list contract + can.manage_projects + statuses', function () use (&$provisioned): void {
    $tenant = provisionWorkPropTenant('Index Prop Co', 'indexprop');
    $provisioned = [$tenant];

    $owner = $tenant->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@indexprop.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        // One open + one done task → task_count 2, open_task_count 1.
        Task::factory()->for($project)->create(['status' => TaskStatus::Todo]);
        Task::factory()->for($project)->create(['status' => TaskStatus::Done]);

        return $owner;
    });

    $this->actingAs($owner)
        ->get('http://'.workPropHost($tenant).route('tenant.projects.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Projects/Index')
            ->has('projects', 1, fn ($project) => $project
                ->has('id')
                ->has('name')
                ->has('description')
                ->where('status', ProjectStatus::Active->value)
                ->has('status_label')
                ->has('status_color')
                ->where('task_count', 2)
                ->where('open_task_count', 1)
                ->has('created_at')
            )
            ->where('can.manage_projects', true)
            ->has('statuses', 4, fn ($status) => $status
                ->has('value')
                ->has('label')
                ->has('color')
            )
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('shows can.manage_projects false for a plain member on the index', function () use (&$provisioned): void {
    $tenant = provisionWorkPropTenant('Index Member Prop Co', 'indexmemberprop');
    $provisioned = [$tenant];

    $member = $tenant->run(function (): User {
        User::factory()->owner()->create(['email' => 'owner@indexmemberprop.test']);

        return User::factory()->create(['email' => 'member@indexmemberprop.test', 'role' => MemberRole::Member]);
    });

    $this->actingAs($member)
        ->get('http://'.workPropHost($tenant).route('tenant.projects.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Projects/Index')
            ->where('can.manage_projects', false)
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('Tenant/Projects/Show exposes the exact board contract (project, columns, members, priorities, filters)', function () use (&$provisioned): void {
    $tenant = provisionWorkPropTenant('Show Prop Co', 'showprop');
    $provisioned = [$tenant];

    [$owner, $projectId, $assigneeId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@showprop.test', 'name' => 'Show Owner']);
        $assignee = User::factory()->create(['email' => 'assignee@showprop.test', 'name' => 'Show Assignee']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        Task::factory()->for($project)->create([
            'status' => TaskStatus::Todo,
            'assigned_to' => $assignee->getKey(),
        ]);
        Task::factory()->for($project)->create([
            'status' => TaskStatus::InProgress,
            'assigned_to' => null,
        ]);

        return [$owner, $project->getKey(), $assignee->getKey()];
    });

    $this->actingAs($owner)
        ->get('http://'.workPropHost($tenant).route('tenant.projects.show', ['project' => $projectId], absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Projects/Show')
            ->has('project', fn ($project) => $project
                ->where('id', $projectId)
                ->has('name')
                ->has('description')
                ->where('status', ProjectStatus::Active->value)
                ->has('status_label')
                ->has('status_color')
                ->where('is_archived', false)
                ->has('available_transitions')
            )
            // One column per TaskStatus (todo/in_progress/done = 3).
            ->has('columns', 3, fn ($column) => $column
                ->has('status')
                ->has('label')
                ->has('color')
                ->has('tasks')
            )
            // members is tenant-scoped: owner + assignee = 2.
            ->has('members', 2, fn ($member) => $member
                ->has('id')
                ->has('name')
                ->missing('email')
                ->missing('password')
            )
            ->has('priorities', 4, fn ($priority) => $priority
                ->has('value')
                ->has('label')
                ->has('color')
            )
            ->has('filters', fn ($filters) => $filters
                ->where('status', null)
                ->where('assignee', null)
            )
            ->where('can.manage_projects', true)
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('Tenant/Projects/Show task cards carry priority + assignee + transition shapes', function () use (&$provisioned): void {
    $tenant = provisionWorkPropTenant('Show Task Prop Co', 'showtaskprop');
    $provisioned = [$tenant];

    [$owner, $projectId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@showtaskprop.test']);
        $assignee = User::factory()->create(['email' => 'assignee@showtaskprop.test', 'name' => 'Picard']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        Task::factory()->for($project)->create([
            'status' => TaskStatus::Todo,
            'assigned_to' => $assignee->getKey(),
        ]);

        return [$owner, $project->getKey()];
    });

    $this->actingAs($owner)
        ->get('http://'.workPropHost($tenant).route('tenant.projects.show', ['project' => $projectId], absolute: false))
        ->assertOk()
        ->assertInertia(function ($page): void {
            /** @var array<int, array<string, mixed>> $columns */
            $columns = $page->toArray()['props']['columns'];
            $todoColumn = collect($columns)->firstWhere('status', TaskStatus::Todo->value);

            expect($todoColumn)->not->toBeNull()
                ->and($todoColumn['tasks'])->toHaveCount(1);

            $task = $todoColumn['tasks'][0];

            expect($task)->toHaveKeys([
                'id', 'title', 'description', 'status', 'priority',
                'priority_label', 'priority_color', 'assignee', 'due_date',
                'available_transitions',
            ])
                ->and($task['assignee'])->toMatchArray(['name' => 'Picard'])
                ->and($task['assignee'])->toHaveKey('id');
        });

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
