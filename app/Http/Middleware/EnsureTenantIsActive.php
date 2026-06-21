<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block a suspended (or otherwise non-servable) tenant from serving traffic
 * (slice 003, §3 CONTRACT; OQ-2 → 503).
 *
 * Runs ONLY inside the tenant route group, AFTER InitializeTenancyByDomain +
 * PreventAccessFromCentralDomains, so `tenant()` is the identified central
 * registry record. It reads ONLY the central `status` enum (already loaded on
 * the tenant model) — it never queries or mutates per-tenant data. Modeled on
 * stancl's CheckTenantForMaintenanceMode: a non-Active tenant 503s with a
 * Retry-After hint; only an Active tenant serves.
 */
final class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant || ! $tenant->status->isServable()) {
            abort(503, 'Service Unavailable', ['Retry-After' => '3600']);
        }

        return $next($request);
    }
}
