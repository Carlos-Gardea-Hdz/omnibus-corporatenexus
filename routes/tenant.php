<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\LandingController;
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
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function (): void {
    Route::get('/', [LandingController::class, 'index'])->name('tenant.landing');
});
