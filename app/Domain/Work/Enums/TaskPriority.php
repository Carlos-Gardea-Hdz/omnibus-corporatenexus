<?php

declare(strict_types=1);

namespace App\Domain\Work\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Task priority (slice 004, tenant context). Backed enum, no magic strings.
 * `weight()` gives a stable 1–4 ordering for board/list sorting.
 */
#[TypeScript]
enum TaskPriority: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Urgent = 'urgent';

    /** Ordering weight — higher number = more urgent. */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    /** Translated label (work.task_priority.low|medium|high|urgent). */
    public function label(): string
    {
        return __('work.task_priority.'.$this->value);
    }

    /** Accent color per priority for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Low => '#64748B',
            self::Medium => '#2563EB',
            self::High => '#F59E0B',
            self::Urgent => '#DC2626',
        };
    }
}
