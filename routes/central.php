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
    Route::get('/provisioning/{tenant}', [ProvisioningStatusController::class, 'show'])
        ->name('central.provisioning');

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
    });
});
