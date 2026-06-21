<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\Auth\LoginController;
use App\Http\Controllers\Tenant\DashboardController;
use App\Http\Controllers\Tenant\LandingController;
use App\Http\Controllers\Tenant\MemberController;
use App\Http\Middleware\EnsureTenantIsActive;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant routes
|--------------------------------------------------------------------------
|
| Served on tenant (sub)domains. Tenancy is initialized from the request host
| (cached lookup) and central domains are rejected outright. Tenant identity
| is derived ONLY by the identification middleware — never from client input
| (multitenancy §2).
|
| Auth lives INSIDE this group: once InitializeTenancyByDomain swaps the default
| connection to the tenant DB, the `web` guard authenticates against the TENANT
| `users` table. That swap is the cross-tenant auth isolation — a user of A is
| physically absent from B's database (slice-002 §0). No new guard.
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    // A SUSPENDED tenant (central status ≠ Active) stops serving: 503 with a
    // Retry-After hint. Runs AFTER identification so `tenant()` is resolved, and
    // reads ONLY the central status enum — never per-tenant data (slice 003 §3).
    EnsureTenantIsActive::class,
])->group(function (): void {
    Route::get('/', [LandingController::class, 'index'])->name('tenant.landing');

    /*
     * Session authentication (slice-002 §7). The `guest` alias keeps an already
     * authenticated member off the login screen; `auth` gates everything below.
     * Login POST is brute-force throttled (throttle:6,1). Web validation is 302 +
     * session errors (never 422) via the LoginData DTO; the credential error is
     * generic (no member enumeration).
     */
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('tenant.login');
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('tenant.login.store');
    });

    /*
     * Authenticated tenant shell (slice-002 §7). A guest hitting any of these is
     * redirected to tenant.login (bootstrap/app.php redirectGuestsTo, resolved on
     * the tenant host). Member management is gated further inside MemberController:
     * a `member` POSTing store/update/destroy is a 403. {user} binds on the tenant
     * connection — a cross-tenant id 404s.
     */
    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('tenant.logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('tenant.dashboard');

        Route::get('/members', [MemberController::class, 'index'])->name('tenant.members.index');
        Route::post('/members', [MemberController::class, 'store'])->name('tenant.members.store');
        Route::patch('/members/{user}', [MemberController::class, 'update'])->name('tenant.members.update');
        Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('tenant.members.destroy');
    });
});
