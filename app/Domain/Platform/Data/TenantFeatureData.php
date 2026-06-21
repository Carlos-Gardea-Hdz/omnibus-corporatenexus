<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Tenancy\Enums\TenantFeature;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A single feature flag resolved for a tenant on the detail page (slice 003):
 * its catalogue value, a human label and whether the tenant's current plan
 * unlocks it. Generates the TS `TenantFeatureData` type.
 */
#[TypeScript]
final class TenantFeatureData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $active,
    ) {}

    public static function fromFeature(TenantFeature $feature, bool $active): self
    {
        return new self(
            value: $feature->value,
            label: self::labelFor($feature),
            active: $active,
        );
    }

    /**
     * Display label for a feature. Kept local to the console DTO (slice 003) so
     * the shared TenantFeature enum stays a pure catalogue (no enum change, §0).
     */
    private static function labelFor(TenantFeature $feature): string
    {
        return match ($feature) {
            TenantFeature::AdvancedAnalytics => 'Advanced Analytics',
            TenantFeature::SsoSaml => 'SSO (SAML)',
            TenantFeature::AuditLogExport => 'Audit Log Export',
            TenantFeature::BetaWorkspaceUi => 'Beta Workspace UI',
        };
    }
}
