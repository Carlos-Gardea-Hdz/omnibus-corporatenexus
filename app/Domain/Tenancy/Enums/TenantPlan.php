<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Billing plan. Drives Pennant feature gating. Central data only —
 * NEVER resolve plan from inside a tenant database.
 */
enum TenantPlan: string
{
    case Free = 'free';
    case Team = 'team';
    case Business = 'business';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Free => __('tenancy.plan.free'),
            self::Team => __('tenancy.plan.team'),
            self::Business => __('tenancy.plan.business'),
            self::Enterprise => __('tenancy.plan.enterprise'),
        };
    }

    /**
     * Enterprise tenants are promoted to a dedicated database for isolation;
     * the rest may share. See multitenancy rules §1 (hybrid strategy).
     */
    public function requiresDedicatedDatabase(): bool
    {
        return $this === self::Enterprise;
    }

    /**
     * Maximum seats per plan. 0 = unlimited.
     */
    public function seatLimit(): int
    {
        return match ($this) {
            self::Free => 3,
            self::Team => 15,
            self::Business => 100,
            self::Enterprise => 0,
        };
    }

    /**
     * Monthly price in INTEGER CENTS (never float). 0 = free / "contact sales".
     */
    public function priceCents(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Team => 2900,
            self::Business => 9900,
            self::Enterprise => 0, // contact sales
        };
    }
}
