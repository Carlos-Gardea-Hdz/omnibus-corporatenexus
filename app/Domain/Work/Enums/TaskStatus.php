<?php

declare(strict_types=1);

namespace App\Domain\Work\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Task lifecycle status (slice 004, tenant context). The enum is the single
 * source of truth for legal transitions — Actions call canTransitionTo() before
 * mutating.
 *
 * Transition graph (everything else — including any self→self — is illegal):
 *   todo        → in_progress
 *   in_progress → done, todo
 *   done        → in_progress      (reopen)
 */
#[TypeScript]
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';

    /**
     * The states this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress],
            self::InProgress => [self::Done, self::Todo],
            self::Done => [self::InProgress],
        };
    }

    /** Guard for the lifecycle — the authoritative legality check. */
    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions(), strict: true);
    }

    /** Translated label (work.task_status.todo|in_progress|done). */
    public function label(): string
    {
        return __('work.task_status.'.$this->value);
    }

    /** Accent color per status for board columns/badges. */
    public function color(): string
    {
        return match ($this) {
            self::Todo => '#64748B',
            self::InProgress => '#2563EB',
            self::Done => '#16A34A',
        };
    }
}
