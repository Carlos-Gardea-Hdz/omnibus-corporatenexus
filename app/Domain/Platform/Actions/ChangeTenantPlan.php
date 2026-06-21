<?php

declare(strict_types=1);

namespace App\Domain\Platform\Actions;

use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Change a tenant's billing plan from the platform console (slice 003) on the
 * CENTRAL registry. The seat limit and feature set are NOT stored — they are
 * derived live from the plan (TenantPlan::seatLimit() + the Pennant resolvers),
 * so flipping the plan column atomically re-grants/re-gates seats and features.
 *
 * Central-only: writes the central `tenants` registry, never a tenant DB, and
 * touches NO payment provider (billing is deferred). On a DOWNGRADE we never
 * remove members (grandfathering, OQ-1): existing over-limit membership is
 * flagged on the detail view, not pruned here.
 *
 * Pennant flags for this tenant are flushed so a newly-unlocked or newly-gated
 * feature resolves against the new plan on the very next read. Single-op central
 * transaction; clean seam for the deferred audit log.
 */
final readonly class ChangeTenantPlan
{
    public function handle(Tenant $tenant, TenantPlan $newPlan): Tenant
    {
        $centralConnection = config()->string('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use ($tenant, $newPlan): Tenant {
            $tenant->plan = $newPlan;
            $tenant->save();

            // Drop any persisted flag values for this tenant so plan-derived
            // features re-resolve against the new plan on the next read
            // (multitenancy §4 — flags follow central plan data, not stored bits).
            Feature::for($tenant)->forget(
                array_map(static fn (TenantFeature $f): string => $f->value, TenantFeature::cases()),
            );

            return $tenant;
        });
    }
}
