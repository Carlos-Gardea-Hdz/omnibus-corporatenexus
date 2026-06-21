<?php

declare(strict_types=1);

use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Central platform-admin authentication (CONTRACT §7 test list 1–4).
 *
 * The platform console is a CENTRAL-ONLY surface: a platform admin authenticates
 * against the central `platform_admins` table through a DEDICATED `admin` guard
 * — never the tenant `web` guard, never inside tenant context. Web validation is
 * 302 + a generic, non-enumerating session error (NEVER a 422 JSON body), and the
 * `auth:admin` group is gated so guests are bounced to the central login.
 *
 * These exercises live entirely on the central connection (no physical tenant DB
 * is provisioned), so RefreshDatabase is enough — the `tenant`-DB isolation crown
 * lives in PlatformAdminIsolationTest on real PostgreSQL.
 */
uses(RefreshDatabase::class);

/** The central host the console is served on. */
function centralHost(): string
{
    return (string) config('app.central_domain');
}

it('logs a platform admin in against the central admin guard and redirects to the dashboard (302, authenticated)', function (): void {
    PlatformAdmin::factory()->create([
        'email' => 'ops@nexus.test',
        'password' => 'correct-horse-battery',
    ]);

    $response = $this->post('http://'.centralHost().'/admin/login', [
        'email' => 'ops@nexus.test',
        'password' => 'correct-horse-battery',
    ]);

    $response->assertStatus(302)
        ->assertSessionHasNoErrors()
        ->assertRedirect('http://'.centralHost().route('platform.dashboard', absolute: false));

    expect(auth()->guard('admin')->check())->toBeTrue()
        // The tenant `web` guard is NOT touched by a platform login.
        ->and(auth()->guard('web')->check())->toBeFalse();
});

it('rejects bad credentials with 302 + a generic session error and stays a guest (NEVER 422, non-enumerating)', function (): void {
    PlatformAdmin::factory()->create([
        'email' => 'real@nexus.test',
        'password' => 'the-real-password',
    ]);

    // Wrong password → 302 + session error, never a 422 JSON body.
    $this->post('http://'.centralHost().'/admin/login', [
        'email' => 'real@nexus.test',
        'password' => 'WRONG-password',
    ])->assertStatus(302)->assertSessionHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();

    // A non-existent email is rejected IDENTICALLY (no admin enumeration).
    $this->post('http://'.centralHost().'/admin/login', [
        'email' => 'ghost@nexus.test',
        'password' => 'anything',
    ])->assertStatus(302)->assertSessionHasErrors('email');

    expect(auth()->guard('admin')->check())->toBeFalse();
});

it('gates the console: a guest is redirected to the platform login, an authed admin gets 200', function (): void {
    $admin = PlatformAdmin::factory()->create(['email' => 'gate@nexus.test']);

    $loginUrl = 'http://'.centralHost().route('platform.login', absolute: false);

    // Guest → every console route bounces to the CENTRAL platform login (R1).
    $this->get('http://'.centralHost().route('platform.dashboard', absolute: false))
        ->assertStatus(302)
        ->assertRedirect($loginUrl);

    // Authenticated on the admin guard → 200.
    $this->actingAs($admin, 'admin')
        ->get('http://'.centralHost().route('platform.dashboard', absolute: false))
        ->assertOk();
});

it('logs out: 302 to the platform login and the admin session is no longer authenticated', function (): void {
    $admin = PlatformAdmin::factory()->create(['email' => 'bye@nexus.test']);

    $response = $this->actingAs($admin, 'admin')
        ->post('http://'.centralHost().route('platform.logout', absolute: false));

    $response->assertStatus(302)
        ->assertRedirect('http://'.centralHost().route('platform.login', absolute: false));

    expect(auth()->guard('admin')->check())->toBeFalse();
});
