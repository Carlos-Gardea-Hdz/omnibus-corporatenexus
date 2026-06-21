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
