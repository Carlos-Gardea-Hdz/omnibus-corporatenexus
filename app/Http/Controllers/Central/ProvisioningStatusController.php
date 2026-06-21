<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central provisioning-status page. Reads ONLY the central registry (tenant
 * row + its domain) — it never enters tenant context or queries tenant data
 * (multitenancy §1, no central↛tenant coupling). The React page polls this
 * endpoint until the queued pipeline flips the status to Active.
 *
 * The page itself is reachable ONLY behind a temporary SIGNED URL (W2): the
 * registrant is redirected here with a signature, so a stranger who guesses the
 * tenant UUID cannot reach the page or consume the owner credential. The route
 * carries the `signed` middleware; the React poller's `router.reload` preserves
 * the query signature so polling keeps working.
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

        $temp = $tenant->getAttribute('owner_temp_password');
        $canReveal = $isActive && is_string($temp) && $temp !== '';

        return Inertia::render('Central/Provisioning', [
            'tenant' => TenantData::fromModel($tenant),
            'tenant_url' => $tenantUrl,
            'is_active' => $isActive,
            // The poller does NOT carry the credential anymore (W4): the plaintext
            // is fetched once, deliberately, via the dedicated signed reveal route
            // below — never read-and-cleared on a poll tick.
            'can_reveal_credential' => $canReveal,
            'reveal_credential_url' => $canReveal
                ? URL::temporarySignedRoute('central.provisioning.credential', now()->addHours(2), ['tenant' => $tenant])
                : null,
        ]);
    }

    /**
     * Deliberate single-read reveal of the owner's one-time temp password
     * (W2 + W4). The seeder parks the plaintext on the tenant's CENTRAL `data`
     * column; this endpoint hands it out exactly once and clears it.
     *
     * Unlike the old clear-on-poll-read path, this is a JSON endpoint the React
     * page hits ONCE — not on every 3s poll tick — so two concurrent ticks can
     * never race to read null and overwrite the prop. It is idempotent and
     * double-fire resilient: a second call after the credential has been handed
     * out returns null (200) instead of throwing. Behind the signed URL it is
     * acceptable for the value to persist until this deliberate reveal.
     */
    public function reveal(Tenant $tenant): JsonResponse
    {
        if (! $tenant->status->isServable()) {
            return response()->json(['owner_temp_password' => null]);
        }

        $temp = $tenant->getAttribute('owner_temp_password');

        if (! is_string($temp) || $temp === '') {
            // Already revealed (or never set) — idempotent no-op, never a 500.
            return response()->json(['owner_temp_password' => null]);
        }

        // Clear the one-time credential from the central registry so it is
        // revealed exactly once. Unsetting the virtual attribute removes it from
        // the encoded `data` JSON on save (never logged — PII stays out of logs).
        unset($tenant->owner_temp_password);
        $tenant->save();

        return response()->json(['owner_temp_password' => $temp]);
    }
}
