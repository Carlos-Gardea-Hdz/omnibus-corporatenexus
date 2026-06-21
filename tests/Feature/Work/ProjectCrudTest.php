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
 * Project CRUD + role gating in tenant context (CONTRACT §C6/§C7, test list).
 *
 *  - admin+ create a project (302 + a `planning` row, created_by = actor);
 *  - a plain member POSTing store/update/transition gets 403;
 *  - the index lists ONLY this tenant's projects;
 *  - an invalid create (name too short / missing) is 302 + session errors,
 *    NEVER 422 — Spatie Data resolves via the controller signature on the web
 *    guard, which redirects back with errors.
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE is forbidden inside a
 * transaction). Provisioning is synchronous; the tenant is marked Active so
 * EnsureTenantIsActive serves its routes.
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

function provisionProjectTenant(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Business): Tenant
{
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: $name,
        subdomain: $subdomain,
        ownerEmail: "owner@{$subdomain}.test",
        plan: $plan,
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

function projectHost(Tenant $tenant): string
{
    return (string) $tenant->domains()->value('domain');
}

it('lets an admin+ create a project: 302, no errors, a planning row stamped with created_by', function () use (&$provisioned): void {
    $tenant = provisionProjectTenant('Create Project Co', 'createproj');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@createproj.test']));
    $host = projectHost($tenant);

    $this->actingAs($owner)
        ->post('http://'.$host.'/projects', [
            'name' => 'Website Redesign',
            'description' => 'Refresh the public marketing site.',
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    /** @var array{count:int, status:string|null, created_by:int|null} $row */
    $row = $tenant->run(function (): array {
        $project = Project::query()->where('name', 'Website Redesign')->first();

        return [
            'count' => Project::query()->count(),
            'status' => $project?->status->value,
            'created_by' => $project?->created_by,
        ];
    });

    expect($row['count'])->toBe(1)
        ->and($row['status'])->toBe(ProjectStatus::Planning->value)
        ->and($row['created_by'])->toBe($owner->getKey());

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('forbids a plain member from creating a project (403, no row written)', function () use (&$provisioned): void {
    $tenant = provisionProjectTenant('Gate Project Co', 'gateproj');
    $provisioned = [$tenant];

    $member = $tenant->run(function (): User {
        User::factory()->owner()->create(['email' => 'owner@gateproj.test']);

        return User::factory()->create(['email' => 'member@gateproj.test', 'role' => MemberRole::Member]);
    });

    $this->actingAs($member)
        ->post('http://'.projectHost($tenant).'/projects', [
            'name' => 'Sneaky Project',
            'description' => null,
        ])
        ->assertStatus(403);

    expect($tenant->run(fn (): int => Project::query()->count()))->toBe(0);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('forbids a plain member from updating a project (403, name unchanged)', function () use (&$provisioned): void {
    $tenant = provisionProjectTenant('Gate Update Co', 'gateupdate');
    $provisioned = [$tenant];

    [$member, $projectId] = $tenant->run(function (): array {
        User::factory()->owner()->create(['email' => 'owner@gateupdate.test']);
        $member = User::factory()->create(['email' => 'member@gateupdate.test', 'role' => MemberRole::Member]);
        $project = Project::factory()->create(['name' => 'Original Name']);

        return [$member, $project->getKey()];
    });

    $this->actingAs($member)
        ->patch('http://'.projectHost($tenant).'/projects/'.$projectId, [
            'name' => 'Hijacked Name',
            'description' => null,
        ])
        ->assertStatus(403);

    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('name')))
        ->toBe('Original Name');

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('lets an admin+ update a project (302, no errors, the row reflects the new name)', function () use (&$provisioned): void {
    $tenant = provisionProjectTenant('Edit Project Co', 'editproj');
    $provisioned = [$tenant];

    [$owner, $projectId] = $tenant->run(function (): array {
        $owner = User::factory()->owner()->create(['email' => 'owner@editproj.test']);
        $project = Project::factory()->create(['name' => 'Before']);

        return [$owner, $project->getKey()];
    });

    $this->actingAs($owner)
        ->patch('http://'.projectHost($tenant).'/projects/'.$projectId, [
            'name' => 'After',
            'description' => 'Updated copy.',
        ])
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    expect($tenant->run(fn (): ?string => Project::query()->whereKey($projectId)->value('name')))
        ->toBe('After');

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('rejects an invalid create with 302 + session errors, NEVER 422', function () use (&$provisioned): void {
    $tenant = provisionProjectTenant('Invalid Project Co', 'invalidproj');
    $provisioned = [$tenant];

    $owner = $tenant->run(fn (): User => User::factory()->owner()->create(['email' => 'owner@invalidproj.test']));

    // Name below the Min(3) rule → graceful web rejection (redirect + errors).
    $this->actingAs($owner)
        ->post('http://'.projectHost($tenant).'/projects', [
            'name' => 'no',
            'description' => null,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors('name');

    expect($tenant->run(fn (): int => Project::query()->count()))->toBe(0);

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('lists only the acting tenant projects (the index never bleeds across tenants)', function () use (&$provisioned): void {
    $tenantA = provisionProjectTenant('Index A Co', 'indexa');
    $tenantB = provisionProjectTenant('Index B Co', 'indexb');
    $provisioned = [$tenantA, $tenantB];

    $ownerA = $tenantA->run(function (): User {
        $owner = User::factory()->owner()->create(['email' => 'owner@indexa.test']);
        Project::factory()->count(2)->create();

        return $owner;
    });

    $tenantB->run(function (): void {
        User::factory()->owner()->create(['email' => 'owner@indexb.test']);
        Project::factory()->count(5)->create();
    });

    $this->actingAs($ownerA)
        ->get('http://'.projectHost($tenantA).route('tenant.projects.index', absolute: false))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Tenant/Projects/Index')
            // Tenant A provisioned exactly 2 projects; B's five are invisible.
            ->has('projects', 2)
        );

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
