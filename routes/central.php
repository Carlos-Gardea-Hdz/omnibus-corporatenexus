<?php

declare(strict_types=1);

use App\Http\Controllers\Central\Auth\PlatformLoginController;
use App\Http\Controllers\Central\PlatformDashboardController;
use App\Http\Controllers\Central\PlatformTenantController;
use App\Http\Controllers\Central\ProvisioningStatusController;
use App\Http\Controllers\Central\TenantRegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central routes
|--------------------------------------------------------------------------
|
| Constrained to the apex/central domain in bootstrap/app.php. Registration,
| billing and platform administration live here. These routes run against the
| CENTRAL database and never enter tenant context.
|
*/

Route::middleware('web')->group(function (): void {
    Route::get('/', static fn () => inertia('Central/Welcome'))->name('central.home');

    Route::get('/register', [TenantRegistrationController::class, 'create'])
        ->name('tenant.register');

    // Provisioning is rate-limited (security §8) to deter abuse/enumeration.
    Route::post('/register', [TenantRegistrationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('tenant.register.store');

    // Provisioning-status page. Route-model binding resolves the Tenant on the
    // central connection (UUIDv7 key). The React page polls it while pending.
    //
    // SIGNED (W2): only the registrant — who holds the temporary signed URL the
    // registration redirect handed them — can reach this page or the owner
    // credential. A stranger who guesses the tenant UUID gets a 403. The React
    // poller's `router.reload` preserves the query signature, so polling works.
    Route::middleware('signed')->group(function (): void {
        Route::get('/provisioning/{tenant}', [ProvisioningStatusController::class, 'show'])
            ->name('central.provisioning');

        // Deliberate, single-read reveal of the owner's one-time temp password
        // (W4): the page fetches this ONCE when provisioning completes, never on a
        // poll tick — so there is no read-and-clear race. Idempotent + signed.
        Route::get('/provisioning/{tenant}/credential', [ProvisioningStatusController::class, 'reveal'])
            ->name('central.provisioning.credential');
    });

    /*
    |--------------------------------------------------------------------------
    | Platform-admin console (slice 003) — the SaaS operator
    |--------------------------------------------------------------------------
    |
    | A SEPARATE auth surface on the CENTRAL host, gated by the `admin` guard
    | (the `platform_admins` table, distinct from the tenant `web` guard). These
    | routes read the central tenant registry and drive lifecycle transitions —
    | they NEVER initialize tenancy or touch per-tenant data (central↛tenant).
    |
    */

    // Guest login surface. `guest:admin` keeps an authenticated admin off the
    // login screen; the POST is brute-force throttled (security §8). Validation
    // is 302 + session errors (never 422) via PlatformLoginData; the credential
    // error is generic (no admin enumeration).
    Route::middleware('guest:admin')->group(function (): void {
        Route::get('/admin/login', [PlatformLoginController::class, 'create'])
            ->name('platform.login');
        Route::post('/admin/login', [PlatformLoginController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('platform.login.store');
    });

    // Authenticated console. A guest hitting any of these is redirected to
    // platform.login (bootstrap/app.php redirectGuestsTo, `/admin` branch).
    Route::middleware('auth:admin')->prefix('admin')->group(function (): void {
        Route::post('/logout', [PlatformLoginController::class, 'destroy'])
            ->name('platform.logout');

        Route::get('/', [PlatformDashboardController::class, 'index'])
            ->name('platform.dashboard');

        // {tenant} binds on the central connection (UUIDv7) — a missing id 404s.
        Route::get('/tenants/{tenant}', [PlatformTenantController::class, 'show'])
            ->name('platform.tenants.show');
        Route::patch('/tenants/{tenant}/suspend', [PlatformTenantController::class, 'suspend'])
            ->name('platform.tenants.suspend');
        Route::patch('/tenants/{tenant}/reactivate', [PlatformTenantController::class, 'reactivate'])
            ->name('platform.tenants.reactivate');
        Route::patch('/tenants/{tenant}/plan', [PlatformTenantController::class, 'changePlan'])
            ->name('platform.tenants.plan');

        // Operator recovery for stuck/failed tenants (W3): retry re-runs
        // provisioning (Failed → Pending + re-dispatch the pipeline); archive is a
        // graceful exit. Both are guarded by canTransitionTo() in their Actions —
        // an illegal transition is a 302 + error, never a 500.
        Route::patch('/tenants/{tenant}/retry', [PlatformTenantController::class, 'retry'])
            ->name('platform.tenants.retry');
        Route::patch('/tenants/{tenant}/archive', [PlatformTenantController::class, 'archive'])
            ->name('platform.tenants.archive');
    });
});
