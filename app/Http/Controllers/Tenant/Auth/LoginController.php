<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant\Auth;

use App\Domain\Membership\Actions\AuthenticateMember;
use App\Domain\Membership\Data\LoginData;
use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant session authentication (slice-002 §6, CONTRACT). Reached ONLY after
 * InitializeTenancyByDomain swaps the default connection to the tenant DB, so
 * the `web` guard resolves credentials against the TENANT `users` table — this
 * swap IS the cross-tenant auth isolation (a user of A is physically absent
 * from B). No new guard.
 *
 * Anemic by law: the controller renders the screen, delegates the credential
 * check to {@see AuthenticateMember} (which throws a uniform, non-enumerating
 * ValidationException keyed on `email` → 302 + session errors, never 422), and
 * owns only the session lifecycle (regenerate / invalidate). {@see LoginData}
 * is the single source of validation truth — no Form Request. `Illuminate\Http`
 * request type is never imported (arch law): `request()` reaches the session.
 */
final class LoginController extends Controller
{
    public function create(): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return Inertia::render('Auth/Login', [
            'tenant' => TenantData::fromModel($tenant),
        ]);
    }

    public function store(LoginData $data, AuthenticateMember $auth): RedirectResponse
    {
        $auth->handle($data);

        request()->session()->regenerate();

        return redirect()->intended(route('tenant.dashboard'));
    }

    public function destroy(): RedirectResponse
    {
        Auth::guard('web')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('tenant.login');
    }
}
