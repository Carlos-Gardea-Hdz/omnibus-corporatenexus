<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap of the first platform admin (CONTRACT §5 + §7 test list 16, OQ-3).
 *
 * Two opt-in paths mint EXACTLY one admin with a hashed password: the
 * interactive `platform:create-admin` command and the `.env`-gated
 * PlatformAdminSeeder. Neither auto-mints, both are idempotent on email, and the
 * minted admin can immediately log in at the central console.
 */
uses(RefreshDatabase::class);

it('mints exactly one admin via platform:create-admin, hashed and idempotent', function (): void {
    $this->artisan('platform:create-admin', [
        'name' => 'Ops Lead',
        'email' => 'lead@nexus.test',
        'password' => 'a-strong-password',
    ])->assertSuccessful();

    expect(PlatformAdmin::query()->where('email', 'lead@nexus.test')->count())->toBe(1);

    $admin = PlatformAdmin::query()->where('email', 'lead@nexus.test')->firstOrFail();

    // The password is HASHED, never stored in plaintext.
    expect($admin->password)->not->toBe('a-strong-password')
        ->and(Hash::check('a-strong-password', $admin->password))->toBeTrue();

    // Re-running on the same email does NOT create a duplicate (idempotent).
    $this->artisan('platform:create-admin', [
        'name' => 'Ops Lead',
        'email' => 'lead@nexus.test',
        'password' => 'a-strong-password',
    ]);

    expect(PlatformAdmin::query()->where('email', 'lead@nexus.test')->count())->toBe(1);
});

it('the seeder is a no-op unless the .env credentials are present', function (): void {
    // The seeder reads the config seam (env() → config at boot); with no
    // credentials configured it must mint nothing.
    config(['platform.admin.email' => null, 'platform.admin.password' => null]);

    $this->seed(PlatformAdminSeeder::class);

    expect(PlatformAdmin::query()->count())->toBe(0);
});

it('the seeder mints exactly one admin from .env and that admin can log in', function (): void {
    // In production env() feeds the config seam at boot; drive that seam here.
    config([
        'platform.admin.email' => 'seed@nexus.test',
        'platform.admin.password' => 'seed-password-123',
    ]);

    $this->seed(PlatformAdminSeeder::class);
    // Idempotent: a second seed does not duplicate.
    $this->seed(PlatformAdminSeeder::class);

    expect(PlatformAdmin::query()->where('email', 'seed@nexus.test')->count())->toBe(1);

    $admin = PlatformAdmin::query()->where('email', 'seed@nexus.test')->firstOrFail();
    expect(Hash::check('seed-password-123', $admin->password))->toBeTrue();

    // The seeded admin authenticates at the central console (302, no errors).
    $this->post('http://'.config('app.central_domain').'/admin/login', [
        'email' => 'seed@nexus.test',
        'password' => 'seed-password-123',
    ])->assertStatus(302)->assertSessionHasNoErrors();

    expect(auth()->guard('admin')->check())->toBeTrue();
});
