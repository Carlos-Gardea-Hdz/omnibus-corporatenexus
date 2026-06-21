<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Project status transitions (CONTRACT §C2/§C6 TransitionProject). The
 * state-machine graph is enforced server-side:
 *   - a legal edge (planning → active) succeeds (302 + the row flips);
 *   - the archive edge (active → archived) works;
 *   - an illegal edge (archived → completed) is a GRACEFUL 302 + flash error,
 *     NEVER a 500 (InvalidProjectTransitionException → render handler);
 *   - a plain member cannot transition a project → 403.
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

function provisionProjectTransitionTenant(string $name, string $subdomain): Tenant
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

function projTransitionHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('applies a legal transition planning → active (302, no errors, row flips)', function () use (&$provisioned): void {
    $tenant = provisionProjectTransitionTenant('Legal Transition Co', 'legaltrans');
    $provisioned = [$tenant];

    [$owner, $projectId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@legaltrans.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Planning]);

        return [$owner, $project->getKey()];
    });

    $this->actingAs($owner)
        ->patch('http://'.projTransitionHost($tenant).'/projects/'.$projectId.'/status', [
            'status' => ProjectStatus::Active->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('status')?->value))
        ->toBe(ProjectStatus::Active->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('archives an active project (active → archived succeeds)', function () use (&$provisioned): void {
    $tenant = provisionProjectTransitionTenant('Archive Co', 'archiveco');
    $provisioned = [$tenant];

    [$owner, $projectId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@archiveco.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Active]);

        return [$owner, $project->getKey()];
    });

    $this->actingAs($owner)
        ->patch('http://'.projTransitionHost($tenant).'/projects/'.$projectId.'/status', [
            'status' => ProjectStatus::Archived->value,
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('status')?->value))
        ->toBe(ProjectStatus::Archived->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects an illegal transition archived → completed with 302 + flash error, NEVER 500', function () use (&$provisioned): void {
    $tenant = provisionProjectTransitionTenant('Illegal Transition Co', 'illegaltrans');
    $provisioned = [$tenant];

    [$owner, $projectId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@illegaltrans.test']);
        $project = Project::factory()->create(['status' => ProjectStatus::Archived]);

        return [$owner, $project->getKey()];
    });

    $this->actingAs($owner)
        ->patch('http://'.projTransitionHost($tenant).'/projects/'.$projectId.'/status', [
            'status' => ProjectStatus::Completed->value,
        ])
        // Graceful domain rejection: a redirect with a flash error, not a 500.
        ->assertStatus(302)
        ->assertSessionHas('error');

    // The status is untouched — the illegal edge never persisted.
    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('status')?->value))
        ->toBe(ProjectStatus::Archived->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('forbids a plain member from transitioning a project (403, status unchanged)', function () use (&$provisioned): void {
    $tenant = provisionProjectTransitionTenant('Member Transition Co', 'membertrans');
    $provisioned = [$tenant];

    [$member, $projectId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@membertrans.test']);
        $member = User::factory()->create(['email' => 'member@membertrans.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['status' => ProjectStatus::Planning]);

        return [$member, $project->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.projTransitionHost($tenant).'/projects/'.$projectId.'/status', [
            'status' => ProjectStatus::Active->value,
        ])
        ->assertStatus(403);

    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('status')?->value))
        ->toBe(ProjectStatus::Planning->value);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
