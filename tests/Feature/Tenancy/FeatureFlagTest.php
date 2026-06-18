<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantFeature;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

it('gates advanced analytics by plan, scoped to the tenant', function (): void {
    $business = Tenant::factory()->create(['plan' => TenantPlan::Business]);
    $free = Tenant::factory()->create(['plan' => TenantPlan::Free]);

    expect(Feature::for($business)->active(TenantFeature::AdvancedAnalytics->value))->toBeTrue()
        ->and(Feature::for($free)->active(TenantFeature::AdvancedAnalytics->value))->toBeFalse();
});

it('restricts SSO to enterprise tenants', function (): void {
    $enterprise = Tenant::factory()->enterprise()->create();
    $team = Tenant::factory()->create(['plan' => TenantPlan::Team]);

    expect(Feature::for($enterprise)->active(TenantFeature::SsoSaml->value))->toBeTrue()
        ->and(Feature::for($team)->active(TenantFeature::SsoSaml->value))->toBeFalse();
});
