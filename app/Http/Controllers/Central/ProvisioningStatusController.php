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
        $isActive = $tenant->status->isServable();
        $tenantUrl = $isActive && is_string($domain)
            ? 'https://'.$domain
            : null;

        return Inertia::render('Central/Provisioning', [
            'tenant' => TenantData::fromModel($tenant),
            'tenant_url' => $tenantUrl,
            'is_active' => $isActive,
            'owner_temp_password' => $this->revealOwnerTempPassword($tenant, $isActive),
        ]);
    }

    /**
     * Single-read reveal of the owner's one-time temp password (risk N2 fix).
     *
     * The seeder parks the plaintext on the tenant's CENTRAL `data` column so
     * the first ACTIVE render of this page can surface it once (email delivery
     * deferred). The poll-reload stops the instant `is_active` flips, so that
     * first active render is the single reveal window: we read the value AND
     * immediately clear it from the registry (unset the virtual attribute, then
     * save → it is dropped from `data`), so the plaintext is never persisted
     * indefinitely. Any subsequent visit (or a non-active tenant) sees `null`.
     */
    private function revealOwnerTempPassword(Tenant $tenant, bool $isActive): ?string
    {
        if (! $isActive) {
            return null;
        }

        $temp = $tenant->getAttribute('owner_temp_password');

        if (! is_string($temp) || $temp === '') {
            return null;
        }

        // Clear the one-time credential from the central registry so it is
        // revealed exactly once. Unsetting the virtual attribute removes it from
        // the encoded `data` JSON on save (never logged — PII stays out of logs).
        unset($tenant->owner_temp_password);
        $tenant->save();

        return $temp;
    }
}
