<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central provisioning-status page. Reads ONLY the central registry (tenant
 * row + its domain) — it never enters tenant context or queries tenant data
 * (multitenancy §1, no central↛tenant coupling). The React page polls this
 * endpoint until the queued pipeline flips the status to Active.
 */
final class ProvisioningStatusController extends Controller
{
    public function show(Tenant $tenant): Response
    {
        $domain = $tenant->domains()->value('domain');
        $tenantUrl = $tenant->status->isServable() && is_string($domain)
            ? 'https://'.$domain
            : null;

        return Inertia::render('Central/Provisioning', [
            'tenant' => TenantData::fromModel($tenant),
            'tenant_url' => $tenantUrl,
            'is_active' => $tenant->status->isServable(),
        ]);
    }
}
