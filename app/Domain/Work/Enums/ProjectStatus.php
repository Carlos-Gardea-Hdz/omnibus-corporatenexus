<?php

declare(strict_types=1);

namespace App\Domain\Work\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Project lifecycle status (slice 004, tenant context). The enum is the single
 * source of truth for legal transitions — Actions call canTransitionTo() before
 * mutating, so the controller never has to know the legality matrix.
 *
 * Transition graph (everything else — including any self→self — is illegal):
 *   planning  → active, archived
 *   active    → completed, archived
 *   completed → active, archived
 *   archived  → active            (un-archive / reopen)
 *
 * An archived project is read-only for its tasks (every task Action checks
 * isArchived() and throws ProjectArchivedException).
 */
#[TypeScript]
enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * The states this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Planning => [self::Active, self::Archived],
            self::Active => [self::Completed, self::Archived],
            self::Completed => [self::Active, self::Archived],
            self::Archived => [self::Active],
        };
    }

    /** Guard for the lifecycle — the authoritative legality check. */
    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions(), strict: true);
    }

    /** Archived projects are read-only for their tasks. */
    public function isArchived(): bool
    {
        return $this === self::Archived;
    }

    /** Translated label (work.project_status.planning|active|completed|archived). */
    public function label(): string
    {
        return __('work.project_status.'.$this->value);
    }

    /** Accent color per status for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Planning => '#64748B',
            self::Active => '#2563EB',
            self::Completed => '#16A34A',
            self::Archived => '#9CA3AF',
        };
    }
}
