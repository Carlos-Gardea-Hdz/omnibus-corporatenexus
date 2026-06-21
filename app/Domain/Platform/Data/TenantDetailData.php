<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Laravel\Pennant\Feature;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Full detail view of a tenant in the platform console (slice 003). Central
 * registry data only — built from the central `tenants` record + plan-derived
 * Pennant flags, NEVER from inside a tenant DB. `owner_email` is operator-only
 * (R4). Generates the TS `TenantDetailData` type.
 *
 * Seat usage (over_seat_limit / seats_over): the central console may not enter
 * tenant context to count live members (OQ-1). We read a denormalized
 * `member_count` from the schemaless central `data` column when present (the
 * slice-002 seam); absent it, usage reports zero / not over limit — the safe
 * default (OQ-1 fallback c). Grandfathering on downgrade surfaces here once the
 * count is wired, with no member ever removed.
 */
#[TypeScript]
final class TenantDetailData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $subdomain,
        public TenantStatus $status,
        public string $status_label,
        public string $status_color,
        public TenantPlan $plan,
        public string $plan_label,
        public ?string $owner_email,
        public string $created_at,
        public int $seat_limit,
        public int $price_cents,
        /** @var array<int, TenantFeatureData> */
        public array $features,
        public bool $over_seat_limit,
        public int $seats_over,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        $seatLimit = $tenant->plan->seatLimit();
        $memberCount = self::memberCount($tenant);

        // 0 = unlimited: never over the limit. Otherwise a denormalized count
        // above the cap flags grandfathered, over-limit membership (OQ-1).
        $overBy = ($seatLimit > 0 && $memberCount > $seatLimit)
            ? $memberCount - $seatLimit
            : 0;

        return new self(
            id: $tenant->id,
            name: $tenant->name,
            subdomain: TenantSubdomain::fromTenant($tenant),
            status: $tenant->status,
            status_label: $tenant->status->label(),
            status_color: $tenant->status->color(),
            plan: $tenant->plan,
            plan_label: $tenant->plan->label(),
            owner_email: self::ownerEmail($tenant),
            created_at: $tenant->created_at->toIso8601String(),
            seat_limit: $seatLimit,
            price_cents: $tenant->plan->priceCents(),
            features: self::resolveFeatures($tenant),
            over_seat_limit: $overBy > 0,
            seats_over: $overBy,
        );
    }

    /**
     * Resolve every catalogue feature against the tenant's plan via Pennant.
     * The resolvers (AppServiceProvider) rehydrate the tenant by id and read its
     * central plan — no tenant context is entered (multitenancy §4).
     *
     * @return array<int, TenantFeatureData>
     */
    private static function resolveFeatures(Tenant $tenant): array
    {
        return array_map(
            static fn (TenantFeature $feature): TenantFeatureData => TenantFeatureData::fromFeature(
                $feature,
                Feature::for($tenant)->active($feature->value),
            ),
            TenantFeature::cases(),
        );
    }

    /**
     * Denormalized member count from the central `data` column (slice-002 seam).
     * Absent or non-numeric → 0 (OQ-1 fallback c: usage not yet tracked).
     */
    private static function memberCount(Tenant $tenant): int
    {
        $value = $tenant->getAttribute('member_count');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The owner email rides the schemaless central `data` column (set by
     * CreateTenant). Read it via getAttribute — operator-only field (R4).
     */
    private static function ownerEmail(Tenant $tenant): ?string
    {
        $value = $tenant->getAttribute('owner_email');

        return is_string($value) ? $value : null;
    }
}
