<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * Catalogue of feature-flag names (no magic strings — project law).
 * Flags are scoped to the TENANT via Pennant and resolved from central plan
 * data, never from inside a tenant DB (multitenancy §4).
 */
enum TenantFeature: string
{
    case AdvancedAnalytics = 'advanced-analytics';
    case SsoSaml = 'sso-saml';
    case AuditLogExport = 'audit-log-export';
    case BetaWorkspaceUi = 'beta-workspace-ui';
}
