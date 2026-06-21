<?php

declare(strict_types=1);

namespace App\Domain\Membership\Exceptions;

use RuntimeException;

/**
 * Inviting a member would exceed the tenant plan's seat limit (slice 002). Thrown by
 * CreateMember BEFORE any write. Carries a translated message + the field name so the
 * HTTP layer renders a 302 + field error / flash, never a 500. The Membership domain
 * stays free of Illuminate\Http (the render lives in bootstrap/app.php).
 */
final class SeatLimitExceededException extends RuntimeException
{
    public string $field = 'email';

    public static function make(): self
    {
        return new self(__('members.error.seat_limit'));
    }
}
