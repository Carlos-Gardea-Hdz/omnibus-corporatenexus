<?php

declare(strict_types=1);

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
 * Bigint-id collision on a TASK assignee — the subtlety the reviewer called out.
 * Tenant user ids are SEQUENTIAL BIGINTs, so the SAME numeric id (e.g. 7) names
 * a DIFFERENT human in tenant A and tenant B. The cross-tenant isolation for
 * task assignment is the connection swap, NOT id-uniqueness: when A's task is
 * assigned to A's user #N, resolving `$task->assignee` in A's context must
 * return A's user — never B's user #N — even though both rows share that id.
 *
 * We materialize the SAME id as DISTINCT identities in A and B, assign A's task
 * to A's user with that id, then assert (Eloquent relation + raw join) that A's
 * task assignee is A's person, and that B's same-id person is unreachable from A.
 *
 * Builds on the two-tenant provisioning of WorkCrossTenantIsolationTest.
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

function provisionBigintCollisionTenant(string $name, string $subdomain): Tenant
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

it('resolves a task assignee to THIS tenant\'s user even when the same bigint id is a different user in another tenant', function () use (&$provisioned): void {
    $tenantA = provisionBigintCollisionTenant('Bigint Collision A', 'biginta');
    $tenantB = provisionBigintCollisionTenant('Bigint Collision B', 'bigintb');
    $provisioned = [$tenantA, $tenantB];

    // Materialize the SAME numeric id as DISTINCT identities in each tenant.
    // Force a known, shared id so the collision is explicit (not incidental).
    $sharedId = 7;

    $aUserId = $tenantA->run(function () use ($sharedId): int {
        $user = User::factory()->create([
            'id' => $sharedId,
            'name' => 'Alice In Tenant A',
            'email' => 'alice@biginta.test',
        ]);

        return $user->getKey();
    });

    $bUserId = $tenantB->run(function () use ($sharedId): int {
        $user = User::factory()->create([
            'id' => $sharedId,
            'name' => 'Bob In Tenant B',
            'email' => 'bob@bigintb.test',
        ]);

        return $user->getKey();
    });

    // Same id, different humans — the whole point.
    expect($aUserId)->toBe($sharedId)
        ->and($bUserId)->toBe($sharedId);

    // Create a Task in A assigned to A's user #7. (Task ids are UUIDs; the
    // collision under test is on the bigint `assigned_to` FK to `users`.)
    $taskId = $tenantA->run(function () use ($sharedId): string {
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);
        $task = Task::factory()->for($project)->assignedTo($sharedId)->create([
            'title' => 'A-owned, assigned to A#7',
        ]);

        return (string) $task->getKey();
    });

    // In A's context: the assignee resolves to ALICE, never BOB.
    /** @var array{assigned_to:int|null, assignee_name:string|null, raw_name:string|null} $a */
    $a = $tenantA->run(function () use ($taskId): array {
        /** @var Task $task */
        $task = Task::query()->with('assignee')->findOrFail($taskId);

        // Raw join on the SAME connection — independent of the Eloquent relation.
        $raw = DB::selectOne(
            'select u.name from tasks t join users u on u.id = t.assigned_to where t.id = ?',
            [$taskId],
        );

        return [
            'assigned_to' => $task->assigned_to,
            'assignee_name' => $task->assignee?->name,
            'raw_name' => $raw?->name,
        ];
    });

    expect($a['assigned_to'])->toBe($sharedId)
        ->and($a['assignee_name'])->toBe('Alice In Tenant A')
        ->and($a['assignee_name'])->not->toBe('Bob In Tenant B')
        ->and($a['raw_name'])->toBe('Alice In Tenant A');

    // Tenant B never sees A's task at all — the shared id buys B nothing.
    expect($tenantB->run(fn (): bool => Task::query()->whereKey($taskId)->exists()))->toBeFalse();

    // And B's user #7 is a genuinely different person, confirming the collision
    // was real (so the A-side resolution above is a meaningful proof).
    expect($tenantB->run(fn (): ?string => User::query()->whereKey($sharedId)->value('name')))
        ->toBe('Bob In Tenant B');

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
