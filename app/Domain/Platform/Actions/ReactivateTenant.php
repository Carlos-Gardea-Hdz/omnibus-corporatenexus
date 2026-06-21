<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Exceptions\TenantTransitionException;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Reactivate a suspended tenant from the platform console (slice 003):
 * Suspended → Active on the CENTRAL registry, restoring its ability to serve.
 *
 * Central-only: writes the central `tenants` registry, never a tenant DB. Guards
 * BOTH that the current status is Suspended AND that the allowlist permits the
 * transition (default-deny) — an illegal source throws TenantTransitionException
 * (302/422, never 500). Single-op central transaction; clean seam for the
 * deferred audit log.
 */
final readonly class ReactivateTenant
{
    public function handle(Tenant $tenant): Tenant
    {
        $isSuspended = $tenant->status === TenantStatus::Suspended;

        if (! $isSuspended || ! $tenant->status->canTransitionTo(TenantStatus::Active)) {
            throw TenantTransitionException::cannotTransition($tenant->status, TenantStatus::Active);
        }

        $centralConnection = config()->string('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use ($tenant): Tenant {
            $tenant->status = TenantStatus::Active;
            $tenant->save();

            return $tenant;
        });
    }
}
