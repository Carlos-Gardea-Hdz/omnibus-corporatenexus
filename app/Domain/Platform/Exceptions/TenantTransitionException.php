<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Tenancy\Enums\TenantStatus;
use RuntimeException;

/**
 * An illegal tenant lifecycle transition was attempted from the platform console
 * (slice 003) — e.g. suspending a Pending tenant or reactivating an Active one.
 * Thrown by the lifecycle Actions BEFORE any write, guarded by the
 * TenantStatus::canTransitionTo() allowlist (default-deny). Carries a translated
 * message + field so the HTTP layer renders 302 + error (web) / 422 (JSON),
 * never a 500. The Platform domain stays free of Illuminate\Http (the render
 * lives in bootstrap/app.php).
 */
final class TenantTransitionException extends RuntimeException
{
    public string $field = 'status';

    public static function cannotTransition(TenantStatus $from, TenantStatus $to): self
    {
        $exception = new self(__('platform.actions.illegal_transition', [
            'from' => $from->label(),
            'to' => $to->label(),
        ]));

        return $exception;
    }
}
