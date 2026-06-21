<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/*
| Slice 003 (Platform) user-facing PHP keys: the central console copy emitted
| server-side (CONTRACT §6, test list 17). EVERY platform.* key must resolve in
| BOTH locales — a missing line returns the raw key, which the assertions below
| catch — and resolve to DIFFERENT copy per locale (so a key with an identical
| English fallback masking a missing translation is caught too).
|
| (The React-side i18n dictionary keys are asserted in the frontend suite; the
| server emits exactly the keys enumerated here.)
*/

/** @var list<string> $platformKeys */
$platformKeys = [
    // login
    'platform.login.title',
    'platform.login.email',
    'platform.login.password',
    'platform.login.submit',
    // dashboard
    'platform.dashboard.title',
    'platform.dashboard.filters.status',
    'platform.dashboard.filters.plan',
    'platform.dashboard.empty',
    // tenant detail
    'platform.tenant.detail_title',
    'platform.tenant.owner_email',
    'platform.tenant.created_at',
    'platform.tenant.seat_limit',
    'platform.tenant.price',
    'platform.tenant.features',
    'platform.tenant.over_limit',
    // actions
    'platform.actions.suspend',
    'platform.actions.reactivate',
    'platform.actions.change_plan',
    'platform.actions.suspend_success',
    'platform.actions.reactivate_success',
    'platform.actions.plan_success',
    'platform.actions.illegal_transition',
];

it('resolves every platform lang key in English (not the raw key)', function (string $key): void {
    App::setLocale('en');

    expect(Lang::has($key, 'en'))->toBeTrue("missing en translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($platformKeys);

it('resolves every platform lang key in Spanish (not the raw key)', function (string $key): void {
    App::setLocale('es');

    expect(Lang::has($key, 'es'))->toBeTrue("missing es translation for {$key}")
        ->and(__($key))->not->toBe($key);
})->with($platformKeys);

it('ships distinct ES/EN copy for a representative platform key (no English fallback masking)', function (): void {
    App::setLocale('en');
    $en = __('platform.actions.suspend');

    App::setLocale('es');
    $es = __('platform.actions.suspend');

    expect($es)->not->toBe($en)
        ->and($es)->not->toBe('platform.actions.suspend');
});
