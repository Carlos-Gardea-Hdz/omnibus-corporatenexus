<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Jobs\MarkTenantActive;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\DatabaseManager;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Jobs\SeedDatabase;

/**
 * Owner provisioning (CONTRACT §8, test list 6). When the queued pipeline
 * drains (CreateDatabase → migrate → SeedDatabase → MarkTenantActive), the
 * tenant DB ends with EXACTLY ONE user: the Owner, whose email equals the
 * central owner_email, with role=owner and a HASHED password (never plaintext).
 * The temp password surfaced into the central data column lets that owner log
 * in once.
 *
 * Real PostgreSQL 18, no RefreshDatabase (CREATE DATABASE forbidden in a tx).
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

/** Drain the full provisioning pipeline synchronously, returns the Tenant. */
function provisionWithOwner(string $name, string $subdomain, TenantPlan $plan = TenantPlan::Business): Tenant
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

    (new SeedDatabase($tenant))->handle();
    (new MarkTenantActive($tenant))->handle();
    $tenant->refresh();

    return $tenant;
}

it('seeds exactly one owner whose email matches owner_email, with role=owner and a hashed password', function () use (&$provisioned): void {
    $tenant = provisionWithOwner('Provision Owner Co', 'provowner');
    $provisioned = [$tenant];

    $ownerEmail = (string) $tenant->owner_email;

    /** @var array{count:int, email:string|null, role:string|null, password:string|null} $row */
    $row = $tenant->run(function () use ($ownerEmail): array {
        $owner = User::query()->where('email', $ownerEmail)->first();

        return [
            'count' => User::query()->count(),
            'email' => $owner?->email,
            'role' => $owner?->role->value,
            'password' => $owner?->getAttribute('password'),
        ];
    });

    expect($row['count'])->toBe(1)
        ->and($row['email'])->toBe($ownerEmail)
        ->and($row['role'])->toBe(MemberRole::Owner->value)
        // Password is stored HASHED, never as plaintext.
        ->and($row['password'])->not->toBeNull()
        ->and($row['password'])->not->toBe('')
        ->and(str_starts_with((string) $row['password'], '$'))->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');

it('surfaces a one-time owner temp password that actually authenticates the owner', function () use (&$provisioned): void {
    $tenant = provisionWithOwner('Login Owner Co', 'loginowner');
    $provisioned = [$tenant];

    // The temp password is written to the central virtual data column (risk N2).
    $temp = $tenant->owner_temp_password;
    expect($temp)->toBeString()->and($temp)->not->toBe('');

    // The surfaced temp password matches the HASHED tenant-DB record.
    $hashed = $tenant->run(
        fn (): string => (string) User::query()->where('email', (string) $tenant->owner_email)->value('password')
    );
    expect(Hash::check((string) $temp, $hashed))->toBeTrue();

    // And it logs the owner in on the tenant host.
    $host = (string) $tenant->domains()->value('domain');
    $this->post('http://'.$host.'/login', [
        'email' => (string) $tenant->owner_email,
        'password' => (string) $temp,
    ])->assertStatus(302)->assertSessionHasNoErrors();

    expect(auth()->guard('web')->check())->toBeTrue();

    tenancy()->end();
})->skip(! extension_loaded('pdo_pgsql'), 'Requires real PostgreSQL.');
