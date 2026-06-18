<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantStatus;

it('allows only allowlisted lifecycle transitions', function (): void {
    expect(TenantStatus::Pending->canTransitionTo(TenantStatus::Active))->toBeTrue()
        ->and(TenantStatus::Active->canTransitionTo(TenantStatus::Suspended))->toBeTrue()
        ->and(TenantStatus::Suspended->canTransitionTo(TenantStatus::Active))->toBeTrue();
});

it('denies transitions out of the archived terminal state', function (): void {
    foreach (TenantStatus::cases() as $target) {
        expect(TenantStatus::Archived->canTransitionTo($target))->toBeFalse();
    }
});

it('marks only active tenants as servable', function (): void {
    expect(TenantStatus::Active->isServable())->toBeTrue()
        ->and(TenantStatus::Pending->isServable())->toBeFalse()
        ->and(TenantStatus::Suspended->isServable())->toBeFalse();
});
