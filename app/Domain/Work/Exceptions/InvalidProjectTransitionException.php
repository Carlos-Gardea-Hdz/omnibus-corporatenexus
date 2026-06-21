<?php

declare(strict_types=1);

namespace App\Domain\Work\Exceptions;

use App\Domain\Work\Enums\ProjectStatus;
use RuntimeException;

/**
 * An illegal project lifecycle edge was attempted (slice 004). Thrown by
 * TransitionProject when ProjectStatus::canTransitionTo() rejects the target,
 * BEFORE any write. Carries a translated message so the HTTP layer renders a
 * 302 + flash error, never a 500 (the render lives in bootstrap/app.php). The
 * Work domain stays free of Illuminate\Http.
 */
final class InvalidProjectTransitionException extends RuntimeException
{
    public static function between(ProjectStatus $from, ProjectStatus $to): self
    {
        return new self(__('work.errors.invalid_project_transition', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }
}
