<?php

declare(strict_types=1);

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
});
