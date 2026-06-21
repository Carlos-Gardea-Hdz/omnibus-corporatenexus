<?php

declare(strict_types=1);

namespace App\Domain\Work\Exceptions;

use App\Domain\Work\Enums\TaskStatus;
use RuntimeException;

/**
 * An illegal task lifecycle edge was attempted (slice 004). Thrown by
 * TransitionTask when TaskStatus::canTransitionTo() rejects the target, BEFORE
 * any write. Carries a translated message so the HTTP layer renders a 302 +
 * flash error, never a 500. The Work domain stays free of Illuminate\Http.
 */
final class InvalidTaskTransitionException extends RuntimeException
{
    public static function between(TaskStatus $from, TaskStatus $to): self
    {
        return new self(__('work.errors.invalid_task_transition', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }
}
