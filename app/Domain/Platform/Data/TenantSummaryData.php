<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dashboard row for a tenant in the platform console (slice 003). Central
 * registry data only — built from the central `tenants` record, NEVER from
 * inside a tenant DB. `owner_email` is operator-only and must never appear on a
 * public/tenant prop (R4). Generates the TS `TenantSummaryData` type.
 */
#[TypeScript]
final class TenantSummaryData extends Data
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
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
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
        );
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
