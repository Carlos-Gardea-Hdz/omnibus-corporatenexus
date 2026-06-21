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
 * Task status transitions (CONTRACT §C2/§C6 TransitionTask). Any member may
 * move a task along the legal graph (todo → in_progress → done):
 *   - a legal edge (todo → in_progress) succeeds (302 + the row flips);
 *   - an illegal edge (todo → done, which must pass through in_progress) is a
 *     GRACEFUL 302 + flash error, NEVER a 500 (InvalidTaskTransitionException);
 *   - a transition on a task whose project is archived is also gracefully
 *     rejected (ProjectArchivedException → 302 + flash).
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

function provisionTaskTransitionTenant(string $name, string $subdomain): Tenant
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

function taskTransitionHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('applies a legal task transition todo → in_progress (302, no errors, row flips)', function () use (&$provisioned): void {
    $tenant = provisionTaskTransitionTenant('Task Legal Co', 'tasklegal');
    $provisioned = [$tenant];

    [$member, $projectId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@tasklegal.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['status' => TaskStatus::Todo]);

        return [$member, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.taskTransitionHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/status', [
            'status' => TaskStatus::InProgress->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?string => Task::query()->whereKey($taskId)->value('status')?->value))
        ->toBe(TaskStatus::InProgress->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects an illegal task transition todo → done with 302 + flash error, NEVER 500', function () use (&$provisioned): void {
    $tenant = provisionTaskTransitionTenant('Task Illegal Co', 'taskillegal');
    $provisioned = [$tenant];

    [$member, $projectId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@taskillegal.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->create(['status' => TaskStatus::Todo]);

        return [$member, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.taskTransitionHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/status', [
            'status' => TaskStatus::Done->value,
        ])
        ->assertStatus(302)
        ->assertSessionHas('error');

    // Untouched — the illegal edge never persisted.
    expect($tenant->run(fn (): ?string => Task::query()->whereKey($taskId)->value('status')?->value))
        ->toBe(TaskStatus::Todo->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('gracefully rejects transitioning a task on an archived project (302 + flash, NEVER 500)', function () use (&$provisioned): void {
    $tenant = provisionTaskTransitionTenant('Task Archived Trans Co', 'taskarchtrans');
    $provisioned = [$tenant];

    [$member, $projectId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@taskarchtrans.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);
        $task = Task::factory()->for($project)->create(['status' => TaskStatus::Todo]);

        return [$member, $project->getKey(), $task->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.taskTransitionHost($tenant).'/projects/'.$projectId.'/tasks/'.$taskId.'/status', [
            'status' => TaskStatus::InProgress->value,
        ])
        ->assertStatus(302)
        ->assertSessionHas('error');

    expect($tenant->run(fn (): ?string => Task::query()->whereKey($taskId)->value('status')?->value))
        ->toBe(TaskStatus::Todo->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
