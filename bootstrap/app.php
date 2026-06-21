<?php

declare(strict_types=1);

use App\Domain\Membership\Exceptions\CannotRemoveOwnerException;
use App\Domain\Membership\Exceptions\CannotRemoveSelfException;
use App\Domain\Membership\Exceptions\OwnerSingletonException;
use App\Domain\Membership\Exceptions\RoleNotAssignableException;
use App\Domain\Membership\Exceptions\SeatLimitExceededException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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

        // Guests hitting an `auth` route (dashboard / members) are sent to the
        // tenant login. The redirect MUST resolve to the TENANT host the guest
        // actually hit — `route('tenant.login')` would build the URL on the
        // configured app host (the central apex), bouncing the user off-tenant.
        // Building `/login` against the live request scheme+host keeps the
        // redirect on the same tenant subdomain (slice-002 §7, risk N1).
        $middleware->redirectGuestsTo(
            fn (Request $request): string => $request->getSchemeAndHttpHost().'/login',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The five Membership management guards (slice-002 §5 / CONTRACT) are
        // privilege/integrity rules, not server faults: each surfaces as a
        // graceful 302 + a field error on web (422 JSON on API), never a 500.
        // NO seat overflow, privilege escalation or owner loss is ever persisted
        // (the Actions throw as a pre-check, inside the transaction where
        // applicable). Field keys mirror the form control each rule belongs to:
        // an invite-form failure → `email`; a role-assignment failure → `role`;
        // a removal-protection failure → a flash (no field). Mirrors how the CMS
        // surfaces its Identity user-CRUD exceptions.

        // Inviting a member beyond the plan seat ceiling (0 = unlimited): an
        // `email` field error on the invite form (the email input the actor used).
        $exceptions->render(function (SeatLimitExceededException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['email' => $e->getMessage()]);
        });

        // Assigning a role the actor may not grant (e.g. an admin inviting/promoting
        // an `owner`): a `role` field error, mirroring the role select that never
        // offered the option.
        $exceptions->render(function (RoleNotAssignableException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['role' => $e->getMessage()]);
        });

        // The owner own-record / no-self-demote guard (the system keeps exactly one
        // owner; a transfer is the side-effect of promoting someone else): a `role`
        // field error.
        $exceptions->render(function (OwnerSingletonException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['role' => $e->getMessage()]);
        });

        // An actor tried to remove its OWN account (lock-out guard): a flash error.
        $exceptions->render(function (CannotRemoveSelfException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['member' => $e->getMessage()]);
        });

        // An attempt to remove the SOLE owner (the singleton must survive): a flash
        // error — the tenant always retains exactly one owner.
        $exceptions->render(function (CannotRemoveOwnerException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['member' => $e->getMessage()]);
        });
    })->create();
