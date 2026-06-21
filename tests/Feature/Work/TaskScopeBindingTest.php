<?php

declare(strict_types=1);

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
 * Nested-binding scope guard (routes/tenant.php ->scopeBindings()). The task
 * routes are nested under {project}; scopeBindings() forces the bound {task} to
 * belong to the bound {project}. A task of project X addressed under project Y
 * is unresolvable → 404 (NOT a silent cross-project write). We exercise ALL
 * three nested verbs (update / status / assignee) to prove the binding holds on
 * every one. The IN-project request (T under its real project X) still resolves,
 * so the 404 is the scope rejecting the mismatch, not a broken route.
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

function provisionScopeBindingTenant(string $name, string $subdomain): Tenant
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

function scopeBindingHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('404s a task of project X addressed under project Y on every nested verb (scopeBindings); X-scoped resolves', function () use (&$provisioned): void {
    $tenant = provisionScopeBindingTenant('Scope Binding Co', 'scopebind');
    $provisioned = [$tenant];

    [$member, $assigneeId, $projectXId, $projectYId, $taskId] = $tenant->run(function (): array {
        $member = User::factory()->create(['email' => 'member@scopebind.test']);
        $assignee = User::factory()->create(['email' => 'assignee@scopebind.test']);
        $projectX = Project::factory()->create(['status' => ProjectStatus::Active]);
        $projectY = Project::factory()->create(['status' => ProjectStatus::Active]);
        // Task T belongs to project X, NEVER to Y.
        $task = Task::factory()->for($projectX)->create([
            'title' => 'X-owned task',
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Low,
            'assigned_to' => null,
        ]);

        return [$member, $assignee->getKey(), $projectX->getKey(), $projectY->getKey(), $task->getKey()];
    });

    $host = scopeBindingHost($tenant);

    // --- T addressed under the WRONG project Y → 404 on all three verbs -----
    $this->actingAs($member)
        ->patch('http://'.$host.'/projects/'.$projectYId.'/tasks/'.$taskId, [
            'title' => 'cross-project update',
            'priority' => TaskPriority::Urgent->value,
        ])
        ->assertStatus(404);

    $this->actingAs($member)
        ->patch('http://'.$host.'/projects/'.$projectYId.'/tasks/'.$taskId.'/status', [
            'status' => TaskStatus::InProgress->value,
        ])
        ->assertStatus(404);

    $this->actingAs($member)
        ->patch('http://'.$host.'/projects/'.$projectYId.'/tasks/'.$taskId.'/assignee', [
            'assigned_to' => $assigneeId,
        ])
        ->assertStatus(404);

    // The mismatch never wrote: T is untouched (title/status/assignee as seeded).
    /** @var array{title:string|null, status:string|null, assigned_to:int|null} $row */
    $row = $tenant->run(fn (): array => [
        'title' => Task::query()->whereKey($taskId)->value('title'),
        'status' => Task::query()->whereKey($taskId)->value('status')?->value,
        'assigned_to' => Task::query()->whereKey($taskId)->value('assigned_to'),
    ]);

    expect($row['title'])->toBe('X-owned task')
        ->and($row['status'])->toBe(TaskStatus::Todo->value)
        ->and($row['assigned_to'])->toBeNull();

    // --- T addressed under its REAL project X → resolves (proves it's the ----
    // scope, not a dead route, that produced the 404 above).
    $this->actingAs($member)
        ->patch('http://'.$host.'/projects/'.$projectXId.'/tasks/'.$taskId, [
            'title' => 'in-project update',
            'priority' => TaskPriority::High->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?string => Task::query()->whereKey($taskId)->value('title')))
        ->toBe('in-project update');

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
