<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use RuntimeException;

/**
 * A change would violate the owner singleton (slice 002): another actor editing the
 * owner row, or the owner demoting itself via a plain update (demotion is only ever a
 * side-effect of promoting someone else — the transfer swap). Thrown by
 * UpdateMemberRole. Carries a translated message + the `role` field so the HTTP layer
 * renders a 302 + field error, never a 500. No Illuminate\Http in the domain.
 */
final class OwnerSingletonException extends RuntimeException
{
    public string $field = 'role';

    public static function make(): self
    {
        return new self(__('members.error.owner_protected'));
    }
}
