<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy-loading / missing-attribute bugs early in non-prod.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Force HTTPS-generated URLs when serving over TLS (Octane/Traefik).
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->defineFeatureFlags();
    }

    /**
     * Plan-derived feature flags. Resolved per TENANT from central plan data
     * (multitenancy §4) — never branch on hardcoded plan ifs in app code.
     *
     * Because Tenant implements FeatureScopeable (so flags serialize by id for
     * the database store), the resolver receives the serialized identifier; we
     * rehydrate the Tenant to read its plan.
     */
    private function defineFeatureFlags(): void
    {
        Feature::define(
            TenantFeature::AdvancedAnalytics->value,
            fn (mixed $scope): bool => in_array(
                $this->planOf($scope),
                [TenantPlan::Business, TenantPlan::Enterprise],
                true,
            ),
        );

        Feature::define(
            TenantFeature::SsoSaml->value,
            fn (mixed $scope): bool => $this->planOf($scope) === TenantPlan::Enterprise,
        );

        Feature::define(
            TenantFeature::AuditLogExport->value,
            fn (mixed $scope): bool => $this->planOf($scope) === TenantPlan::Enterprise,
        );

        // Staged rollout — default off; toggled per tenant from the admin UI.
        Feature::define(
            TenantFeature::BetaWorkspaceUi->value,
            static fn (): bool => false,
        );
    }

    /**
     * Resolve the plan for a Pennant scope, accepting either a Tenant model or
     * its serialized id (FeatureScopeable identifier).
     */
    private function planOf(mixed $scope): ?TenantPlan
    {
        $tenant = match (true) {
            $scope instanceof Tenant => $scope,
            is_string($scope) => Tenant::query()->find($scope),
            default => null,
        };

        return $tenant?->plan;
    }
}
