<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Central app (registration, billing, admin). Constrained to the
            // apex host so it never collides with tenant subdomain routes.
            Route::domain(config('app.central_domain'))
                ->group(base_path('routes/central.php'));

            // Tenant app — tenancy init + central-domain guard live inside the
            // route file's middleware group (multitenancy §1, §2).
            Route::group([], base_path('routes/tenant.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Order matters: SecurityHeaders sets the CSP nonce Inertia reads.
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
