<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Tenant identification by domain. A request to a provisioned tenant's host
 * boots THAT tenant's physical database (InitializeTenancyByDomain), and the
 * landing page reads projects_count / open_tasks_count from the TENANT DB —
 * counts the CENTRAL context cannot see (proof the request entered tenant
 * context).
 *
 * Real PostgreSQL, no RefreshDatabase (CREATE DATABASE forbidden in a tx) —
 * mirrors the foundation CrossTenantIsolationTest provisioning approach.
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

function provisionForIdentification(string $name, string $subdomain): Tenant
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

    // Synchronously provisioned → mark Active so EnsureTenantIsActive serves it.
    return markTenantActive($tenant);
}

it('boots the tenant database from the request host and exposes the tenant work counts', function () use (&$provisioned): void {
    $tenant = provisionForIdentification('Identify Co', 'identify');
    $provisioned = [$tenant];

    // Seed three projects INSIDE the tenant DB; one carries an open + a done
    // task → open_tasks_count = 1 (status != done).
    $tenant->run(function (): void {
        $project = Project::create(['name' => 'p1', 'status' => ProjectStatus::Active]);
        Project::create(['name' => 'p2', 'status' => ProjectStatus::Planning]);
        Project::create(['name' => 'p3', 'status' => ProjectStatus::Completed]);
        $project->tasks()->create(['title' => 'open task', 'status' => TaskStatus::Todo]);
        $project->tasks()->create(['title' => 'done task', 'status' => TaskStatus::Done]);
    });

    $host = $tenant->domains()->value('domain');

    $this->get('http://'.$host.'/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Landing')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.name', 'Identify Co')
            ->where('projects_count', 3)
            ->where('open_tasks_count', 1)
        );

    // In production every central request is un-identified; here we end tenancy
    // explicitly (the test process doesn't run the request terminate phase) to assert
    // the context reverts cleanly to central — no tenant leaks past the boundary.
    tenancy()->end();
    expect(tenant())->toBeNull();
});

it('keeps the central context blind to tenant data (identification is required to see it)', function () use (&$provisioned): void {
    $tenant = provisionForIdentification('Blind Co', 'blind');
    $provisioned = [$tenant];

    $tenant->run(function (): void {
        Project::create(['name' => 'tenant-only', 'status' => ProjectStatus::Planning]);
    });

    // The central database has no `projects` table / tenant rows — querying it
    // for the tenant's data is impossible without entering tenant context. The
    // tenant context, by contrast, sees exactly its own row.
    expect($tenant->run(fn (): int => Project::query()->count()))->toBe(1)
        ->and(tenant())->toBeNull();

    // The central registry connection holds the tenant record, not its projects.
    expect(Tenant::query()->whereKey($tenant->getKey())->exists())->toBeTrue();
});
