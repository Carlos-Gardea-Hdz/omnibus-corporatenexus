<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantPlan;

/*
| Money is ALWAYS integer cents, NEVER float (project law). priceCents() is the
| single source of truth for the public plan picker; 0 means free / "contact
| sales" (Free + Enterprise).
*/

it('returns the monthly price as integer cents for every plan', function (): void {
    expect(TenantPlan::Free->priceCents())->toBe(0)
        ->and(TenantPlan::Team->priceCents())->toBe(2900)
        ->and(TenantPlan::Business->priceCents())->toBe(9900)
        ->and(TenantPlan::Enterprise->priceCents())->toBe(0);
});

it('always returns an integer (never a float) for price cents', function (TenantPlan $plan): void {
    expect($plan->priceCents())->toBeInt();
})->with(TenantPlan::cases());

it('keeps free and contact-sales plans at zero cents', function (): void {
    expect(TenantPlan::Free->priceCents())->toBe(0)
        ->and(TenantPlan::Enterprise->priceCents())->toBe(0);
});

it('exposes the seat limit alongside the price for the plan picker', function (): void {
    // 0 = unlimited (Enterprise); the picker renders this as "unlimited".
    expect(TenantPlan::Free->seatLimit())->toBe(3)
        ->and(TenantPlan::Team->seatLimit())->toBe(15)
        ->and(TenantPlan::Business->seatLimit())->toBe(100)
        ->and(TenantPlan::Enterprise->seatLimit())->toBe(0);
});
