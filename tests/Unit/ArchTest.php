<?php

declare(strict_types=1);

arch('strict types are declared everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('no debugging helpers leak into the codebase')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('domain enums are backed')
    ->expect('App\Domain\Tenancy\Enums')
    ->toBeEnums();

/*
| Membership domain (slice 002) — same DDD-Lite guarantees as Tenancy. The
| domain is HTTP-agnostic (the App\Domain guard above already forbids
| Illuminate\Http for the whole domain layer; restated here for the slice).
*/
arch('membership domain stays off the HTTP layer')
    ->expect('App\Domain\Membership')
    ->not->toUse('Illuminate\Http');

arch('membership enums are backed')
    ->expect('App\Domain\Membership\Enums')
    ->toBeEnums();

arch('membership actions are final classes')
    ->expect('App\Domain\Membership\Actions')
    ->toBeClasses()
    ->toBeFinal();

arch('membership output DTOs are final')
    ->expect('App\Domain\Membership\Data')
    ->toBeClasses()
    ->toBeFinal();

/*
| Central ↛ tenant-user coupling guard. Central controllers operate only on the
| central registry; the tenant User model belongs to tenant context. (The
| provisioning controller may surface a one-time owner credential as a prop, but
| it must never import or query the tenant User model directly.)
*/
arch('central controllers do not touch the tenant User model')
    ->expect('App\Http\Controllers\Central')
    ->not->toUse('App\Models\User');

/*
| Platform domain (slice 003 — the CENTRAL platform-admin console). Same DDD-Lite
| guarantees as the other domains, PLUS the central↛tenant rule: the console
| operates ONLY on the central registry and must never import or query a
| per-tenant model (App\Models\User / App\Models\Note). The Central controllers
| are already covered above; here we extend the guard to the whole Platform
| domain layer.
*/
arch('platform domain stays off the HTTP layer')
    ->expect('App\Domain\Platform')
    ->not->toUse('Illuminate\Http');

arch('platform actions are final classes')
    ->expect('App\Domain\Platform\Actions')
    ->toBeClasses()
    ->toBeFinal();

arch('platform DTOs are final')
    ->expect('App\Domain\Platform\Data')
    ->toBeClasses()
    ->toBeFinal();

arch('the platform domain never touches the per-tenant User model')
    ->expect('App\Domain\Platform')
    ->not->toUse('App\Models\User');

arch('the platform domain never touches the per-tenant Note model')
    ->expect('App\Domain\Platform')
    ->not->toUse('App\Models\Note');

arch('the tenant-transition exception is final')
    ->expect('App\Domain\Platform\Exceptions\TenantTransitionException')
    ->toBeFinal();

arch('actions are final and live in the domain layer')
    ->expect('App\Domain\Tenancy\Actions')
    ->toBeClasses()
    ->toBeFinal();

arch('domain jobs are final and live in the domain layer')
    ->expect('App\Domain\Tenancy\Jobs')
    ->toBeClasses()
    ->toBeFinal();

arch('output DTOs are final and immutable')
    ->expect('App\Domain\Tenancy\Data')
    ->toBeClasses()
    ->toBeFinal();

arch('controllers do not call the request facade with all()')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\Request');

/*
| The domain layer must never depend on the HTTP layer (DDD-Lite): no
| controller, request, or response leaks into Actions/Jobs/DTOs/Enums/Models.
| Business operations receive DTOs and return entities, never HTTP objects.
*/
arch('the domain layer is HTTP-agnostic')
    ->expect('App\Domain')
    ->not->toUse('Illuminate\Http');

arch('the domain layer never reaches into HTTP controllers')
    ->expect('App\Domain')
    ->not->toUse('App\Http');

/*
| Central ↛ tenant coupling guard. The central registration/provisioning
| controllers operate ONLY on the central registry; they must never import the
| per-tenant Note model (tenant data is read inside tenant context, by the
| tenant landing controller — never from a central controller).
*/
arch('central controllers do not touch per-tenant models')
    ->expect('App\Http\Controllers\Central')
    ->not->toUse('App\Models\Note');

/*
| The provisioning activation job mutates only the central tenant registry;
| it must not import per-tenant models (it runs AFTER tenant context ended).
*/
arch('the activation job does not touch per-tenant models')
    ->expect('App\Domain\Tenancy\Jobs')
    ->not->toUse('App\Models\Note');
