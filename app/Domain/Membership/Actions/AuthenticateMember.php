<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Data\LoginData;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Authenticate a tenant member by email + password (slice 002). Runs AFTER tenant
 * identification, so the default `web` guard resolves against the swapped TENANT
 * `users` table — a user of A can never authenticate on B (distinct physical DBs).
 *
 * One operation. Session regeneration + redirect are the controller's job; this Action
 * stays free of Illuminate\Http and returns the authenticated User, or throws a
 * uniform, non-enumerating ValidationException keyed on `email`.
 */
final class AuthenticateMember
{
    /**
     * @throws ValidationException single generic credential error (no enumeration)
     */
    public function handle(LoginData $data): User
    {
        $email = mb_strtolower(trim($data->email));

        $authenticated = Auth::attempt(
            ['email' => $email, 'password' => $data->password],
            $data->remember,
        );

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
