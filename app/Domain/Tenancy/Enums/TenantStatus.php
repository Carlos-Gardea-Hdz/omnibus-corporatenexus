<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Lifecycle status of a tenant. Lives in the CENTRAL database only.
 */
enum TenantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Archived = 'archived';

    /**
     * Human-readable, bilingual label (ES/EN) resolved by the app locale.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => __('tenancy.status.pending'),
            self::Active => __('tenancy.status.active'),
            self::Suspended => __('tenancy.status.suspended'),
            self::Archived => __('tenancy.status.archived'),
        };
    }

    /**
     * Tailwind-friendly semantic colour token for badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'emerald',
            self::Suspended => 'rose',
            self::Archived => 'slate',
        };
    }

    /**
     * Whether a tenant in this status may serve traffic.
     */
    public function isServable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowlisted lifecycle transitions (default-deny).
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Active, self::Archived], true),
            self::Active => in_array($target, [self::Suspended, self::Archived], true),
            self::Suspended => in_array($target, [self::Active, self::Archived], true),
            self::Archived => false,
        };
    }
}
