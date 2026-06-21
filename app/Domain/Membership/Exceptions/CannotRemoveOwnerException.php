<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use RuntimeException;

/**
 * An actor attempted to remove the sole owner (slice 002). The owner singleton
 * requires the tenant always retain exactly one. Thrown by RemoveMember. Carries a
 * translated message + a null field (a flash) so the HTTP layer renders a 302 + flash,
 * never a 500. No Illuminate\Http in the domain.
 */
final class CannotRemoveOwnerException extends RuntimeException
{
    public ?string $field = null;

    public static function make(): self
    {
        return new self(__('members.error.cannot_remove_owner'));
    }
}
