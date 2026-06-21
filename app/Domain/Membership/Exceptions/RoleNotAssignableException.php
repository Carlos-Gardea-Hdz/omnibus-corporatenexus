<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use RuntimeException;

/**
 * The actor attempted to assign a role outside its authority (slice 002) — e.g. an
 * admin granting owner. Thrown by CreateMember / UpdateMemberRole. Carries a
 * translated message + the `role` field so the HTTP layer renders a 302 + field error,
 * never a 500. No Illuminate\Http in the domain.
 */
final class RoleNotAssignableException extends RuntimeException
{
    public string $field = 'role';

    public static function make(): self
    {
        return new self(__('members.error.role_not_assignable'));
    }
}
