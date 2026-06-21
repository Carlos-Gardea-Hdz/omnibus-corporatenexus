<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * PlatformAdmin model invariants (CONTRACT §2 + §7 test list 18).
 *
 * The central admin identity is keyed by an app-generated UUIDv7 (chronologically
 * sortable, mirroring the tenancy approach), stores its password HASHED (never
 * plaintext), and enforces a unique email. The model lives on the central
 * connection.
 */
uses(RefreshDatabase::class);

it('mints a UUIDv7 primary key on create', function (): void {
    $admin = PlatformAdmin::factory()->create();

    // UUID shape, version-7 nibble in the canonical position.
    expect($admin->getKey())->toBeString()
        ->and($admin->getKey())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('hashes the password (never persists plaintext) via the hashed cast', function (): void {
    $admin = PlatformAdmin::factory()->create(['password' => 'plaintext-secret']);

    expect($admin->password)->not->toBe('plaintext-secret')
        ->and(Hash::check('plaintext-secret', $admin->password))->toBeTrue();
});

it('hides the password and remember_token from array/JSON output', function (): void {
    $admin = PlatformAdmin::factory()->create();

    $array = $admin->toArray();
    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('remember_token');
});

it('enforces a unique email', function (): void {
    PlatformAdmin::factory()->create(['email' => 'dup@nexus.test']);

    expect(fn () => PlatformAdmin::factory()->create(['email' => 'dup@nexus.test']))
        ->toThrow(QueryException::class);
});
