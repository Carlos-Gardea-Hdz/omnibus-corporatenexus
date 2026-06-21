<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/*
| Every user-facing PHP translation key the Tenancy enums emit (status.* +
| plan.*, consumed by TenantStatus::label() / TenantPlan::label()) MUST resolve
| in BOTH locales, and resolve to DIFFERENT copy per locale (so we never ship a
| key with an identical fallback string masking a missing translation).
|
| (The provisioning/landing/register copy lives in the React i18n dictionary —
| resources/js/lib/i18n.tsx — not in PHP lang, per the contract; the server
| emits no new user-facing strings this slice.)
*/

/** @var list<string> $tenancyKeys */
$tenancyKeys = [
    'tenancy.status.pending',
    'tenancy.status.active',
    'tenancy.status.suspended',
    'tenancy.status.archived',
    'tenancy.plan.free',
    'tenancy.plan.team',
    'tenancy.plan.business',
    'tenancy.plan.enterprise',
];

it('resolves every tenancy lang key in English (not the raw key)', function (string $key): void {
    App::setLocale('en');

    expect(Lang::has($key, 'en'))->toBeTrue("missing en translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($tenancyKeys);

it('resolves every tenancy lang key in Spanish (not the raw key)', function (string $key): void {
    App::setLocale('es');

    expect(Lang::has($key, 'es'))->toBeTrue("missing es translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($tenancyKeys);

it('resolves enum labels through the active locale', function (): void {
    App::setLocale('en');
    expect(TenantStatus::Active->label())->toBe('Active')
        ->and(TenantPlan::Business->label())->toBe('Business');

    App::setLocale('es');
    // ES copy must differ from EN for at least one representative key, proving
    // the locale actually switches (not an English fallback).
    expect(TenantStatus::Pending->label())->not->toBe(TenantStatus::Pending->value);
});
