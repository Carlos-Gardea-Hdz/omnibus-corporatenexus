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

arch('controllers do not call the request facade with all()')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\Request');
