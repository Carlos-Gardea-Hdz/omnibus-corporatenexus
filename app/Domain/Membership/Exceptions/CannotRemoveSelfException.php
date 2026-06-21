<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use RuntimeException;

/**
 * An actor attempted to remove their own membership (slice 002). Disallowed so no
 * actor locks itself out mid-session. Thrown by RemoveMember. Carries a translated
 * message + a null field (a flash, not a field error) so the HTTP layer renders a
 * 302 + flash, never a 500. No Illuminate\Http in the domain.
 */
final class CannotRemoveSelfException extends RuntimeException
{
    public ?string $field = null;

    public static function make(): self
    {
        return new self(__('members.error.cannot_remove_self'));
    }
}
