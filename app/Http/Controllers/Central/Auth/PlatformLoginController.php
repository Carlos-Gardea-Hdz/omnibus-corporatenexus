<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central\Auth;

use App\Domain\Platform\Actions\AuthenticatePlatformAdmin;
use App\Domain\Platform\Data\PlatformLoginData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central platform-admin session authentication (slice 003, §3 CONTRACT).
 *
 * Runs on the CENTRAL domain against the default (central) connection — this
 * controller never enters tenant context and never touches per-tenant models.
 * Credentials resolve against the `admin` guard / `platform_admins` provider,
 * which is physically separate from the tenant `web` guard: a tenant user can
 * never authenticate here.
 *
 * Anemic by law: it renders the screen, delegates the credential check to
 * {@see AuthenticatePlatformAdmin} (which throws a uniform, non-enumerating
 * ValidationException keyed on `email` → 302 + session errors, never 422), and
 * owns only the session lifecycle. {@see PlatformLoginData} is the single
 * validation source — no Form Request. `Illuminate\Http` request type is never
 * imported (arch law): `request()` reaches the session.
 */
final class PlatformLoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/PlatformLogin');
    }

    public function store(PlatformLoginData $data, AuthenticatePlatformAdmin $auth): RedirectResponse
    {
        $auth->handle($data);

        request()->session()->regenerate();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(): RedirectResponse
    {
        Auth::guard('admin')->logout();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
