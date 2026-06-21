<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tail of the queued provisioning pipeline (TenancyServiceProvider): once the
 * tenant database has been created, migrated and seeded, flip the CENTRAL
 * registry status Pending → Active so the tenant becomes servable.
 *
 * Runs AFTER stancl's SeedDatabase, which ends tenant context, so we reload the
 * tenant from the central connection by id and never assume tenant context is
 * active. Idempotent: if the status is already Active (re-run / retry), no-op.
 * The transition is guarded by the status allowlist (default-deny) — fail-safe.
 */
final class MarkTenantActive implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
    ) {}

    public function handle(): void
    {
        $centralConnection = config()->string('tenancy.database.central_connection');

        // Reload from CENTRAL: SeedDatabase (the prior pipeline step) ended
        // tenant context, and we must not trust a stale serialized snapshot.
        /** @var Tenant|null $tenant */
        $tenant = Tenant::on($centralConnection)->find($this->tenant->getKey());

        if ($tenant === null) {
            return; // Deleted mid-provisioning — nothing to activate.
        }

        if ($tenant->status === TenantStatus::Active) {
            return; // Idempotent: already activated.
        }

        if (! $tenant->status->canTransitionTo(TenantStatus::Active)) {
            return; // Default-deny: not in a provisionable state.
        }

        $tenant->status = TenantStatus::Active;
        $tenant->save();
    }
}
