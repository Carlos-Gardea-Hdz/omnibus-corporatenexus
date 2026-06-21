<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Data\PlatformLoginData;
use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Authenticate a platform operator against the CENTRAL `admin` guard (slice 003).
 * Runs on the central host with NO tenant context — it resolves the central
 * `platform_admins` table, never the per-tenant `users` table. A tenant member
 * can therefore never authenticate here, and a platform admin can never
 * authenticate on the tenant `web` guard (central↛tenant isolation).
 *
 * One operation. Session regeneration + redirect are the controller's job; this
 * Action stays free of Illuminate\Http and returns the authenticated admin, or
 * throws a uniform, non-enumerating ValidationException keyed on `email`.
 */
final class AuthenticatePlatformAdmin
{
    /**
     * @throws ValidationException single generic credential error (no enumeration)
     */
    public function handle(PlatformLoginData $data): PlatformAdmin
    {
        $email = mb_strtolower(trim($data->email));

        $authenticated = Auth::guard('admin')->attempt(
            ['email' => $email, 'password' => $data->password],
            $data->remember,
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var PlatformAdmin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
