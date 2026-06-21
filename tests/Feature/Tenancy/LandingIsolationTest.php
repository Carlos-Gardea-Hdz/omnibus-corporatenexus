<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\Note;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;

/**
 * Cross-tenant isolation for the data surfaced by the new Tenant/Landing page
 * (notes_count). This complements the foundation CrossTenantIsolationTest by
 * exercising the SAME tenant-owned table (notes) through the landing read path:
 * a note written as tenant A must be invisible AND immutable to tenant B, via
 * Eloquent AND a raw query, and the two tenants must resolve to distinct
 * physical databases.
 *
 * Real PostgreSQL, no RefreshDatabase (CREATE DATABASE forbidden in a tx).
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

function provisionLandingTenant(string $name, string $subdomain): Tenant
{
    /** @var Tenant $tenant */
    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: $name,
        subdomain: $subdomain,
        ownerEmail: "owner@{$subdomain}.test",
        plan: TenantPlan::Team,
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

it('isolates landing note data between tenants (Eloquent and raw; distinct DBs)', function () use (&$provisioned): void {
    $tenantA = provisionLandingTenant('Landing A', 'landa');
    $tenantB = provisionLandingTenant('Landing B', 'landb');
    $provisioned = [$tenantA, $tenantB];

    // Distinct physical databases.
    $dbA = $tenantA->database()->getName();
    $dbB = $tenantB->database()->getName();
    expect($dbA)->not->toBe($dbB)
        ->and($dbA)->toStartWith('tenant_')
        ->and($dbB)->toStartWith('tenant_');

    // Write a note as tenant A.
    tenancy()->initialize($tenantA);
    $note = Note::create(['title' => 'landing-secret', 'body' => 'A-only']);
    $aId = $note->getKey();
    expect(Note::query()->count())->toBe(1);
    tenancy()->end();

    // Tenant B sees nothing — read (Eloquent + raw), update, delete all no-op.
    tenancy()->initialize($tenantB);
    expect(Note::query()->count())->toBe(0)
        ->and(Note::find($aId))->toBeNull();

    $rawB = DB::select('select count(*) as c from notes where title = ?', ['landing-secret']);
    expect((int) $rawB[0]->c)->toBe(0)
        ->and(Note::query()->where('title', 'landing-secret')->update(['body' => 'pwned']))->toBe(0)
        ->and(DB::update('update notes set body = ? where title = ?', ['pwned', 'landing-secret']))->toBe(0)
        ->and(Note::query()->where('title', 'landing-secret')->delete())->toBe(0)
        ->and(DB::delete('delete from notes where title = ?', ['landing-secret']))->toBe(0);
    tenancy()->end();

    // Tenant A's note is intact and its landing count still reflects only its own row.
    expect($tenantA->run(fn (): int => Note::query()->count()))->toBe(1);
    tenancy()->initialize($tenantA);
    expect(Note::find($aId)?->body)->toBe('A-only');
    tenancy()->end();
});
