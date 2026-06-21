<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Platform\Exceptions\TenantTransitionException;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Events\TenantCreated;

/**
 * Retry provisioning for a Failed (or otherwise stuck) tenant from the platform
 * console (W3): flip the CENTRAL registry status back to Pending, then RE-FIRE
 * the stancl provisioning pipeline so a worker re-runs
 * CreateDatabase → MigrateDatabase → SeedDatabase → MarkTenantActive.
 *
 * Central-only: writes the central `tenants` registry, never a tenant DB. The
 * Failed → Pending transition is guarded by the canTransitionTo() allowlist
 * (default-deny) — retrying a tenant that is not in a retryable state (e.g. an
 * Active tenant) throws TenantTransitionException (302 + error on web, never a
 * 500). The status write commits in a central transaction BEFORE the pipeline is
 * re-fired, so the re-dispatched pipeline sees a Pending tenant.
 *
 * Re-firing Stancl's TenantCreated event re-triggers the SAME JobPipeline
 * listener wired in TenancyServiceProvider (queued in prod) — the idiomatic way
 * to replay provisioning without re-creating the central row.
 */
final readonly class RetryTenantProvisioning
{
    public function handle(Tenant $tenant): Tenant
    {
        if (! $tenant->status->canTransitionTo(TenantStatus::Pending)) {
            throw TenantTransitionException::cannotTransition($tenant->status, TenantStatus::Pending);
        }

        $centralConnection = config()->string('tenancy.database.central_connection');

        $tenant = DB::connection($centralConnection)->transaction(function () use ($tenant): Tenant {
            $tenant->status = TenantStatus::Pending;
            $tenant->save();

            return $tenant;
        });

        // Replay the provisioning pipeline (CreateDatabase → migrate → seed →
        // activate). Fired AFTER the Pending commit so the queued pipeline reads a
        // consistent central state.
        event(new TenantCreated($tenant));

        return $tenant;
    }
}
