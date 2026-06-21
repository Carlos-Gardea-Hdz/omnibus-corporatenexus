<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Exceptions\TenantTransitionException;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Archive a tenant from the platform console (W3): move the CENTRAL registry
 * status to Archived. The lifecycle allowlist permits Pending → Archived,
 * Failed → Archived, Active → Archived and Suspended → Archived — so a stuck or
 * failed tenant is no longer a dead end. Archived is terminal (no transitions
 * out).
 *
 * Central-only: writes the central `tenants` registry, never a tenant DB
 * (dropping the tenant DB is a separate, deliberate destructive op). The
 * transition is guarded by canTransitionTo() (default-deny); an illegal source
 * throws TenantTransitionException (302 + error on web, never a 500). Single-op
 * central transaction; clean seam for the deferred audit log.
 */
final readonly class ArchiveTenant
{
    public function handle(Tenant $tenant): Tenant
    {
        if (! $tenant->status->canTransitionTo(TenantStatus::Archived)) {
            throw TenantTransitionException::cannotTransition($tenant->status, TenantStatus::Archived);
        }

        $centralConnection = config()->string('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use ($tenant): Tenant {
            $tenant->status = TenantStatus::Archived;
            $tenant->save();

            return $tenant;
        });
    }
}
