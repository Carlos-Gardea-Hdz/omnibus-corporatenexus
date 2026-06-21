<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Exceptions\TenantTransitionException;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Suspend a tenant from the platform console (slice 003): Active → Suspended on
 * the CENTRAL registry. Once suspended the tenant stops serving traffic — the
 * tenant-group middleware (EnsureTenantIsActive) rejects requests because
 * TenantStatus::Suspended is not servable.
 *
 * Central-only: writes the central `tenants` registry, never a tenant DB. The
 * transition is guarded by the canTransitionTo() allowlist (default-deny) — an
 * illegal source status throws TenantTransitionException (302/422, never 500),
 * never a hardcoded from/to pair. Single-op central transaction leaves a clean
 * seam for the deferred audit log.
 */
final readonly class SuspendTenant
{
    public function handle(Tenant $tenant): Tenant
    {
        if (! $tenant->status->canTransitionTo(TenantStatus::Suspended)) {
            throw TenantTransitionException::cannotTransition($tenant->status, TenantStatus::Suspended);
        }

        $centralConnection = config()->string('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use ($tenant): Tenant {
            $tenant->status = TenantStatus::Suspended;
            $tenant->save();

            return $tenant;
        });
    }
}
