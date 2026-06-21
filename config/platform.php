<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Platform console (slice 003)
|--------------------------------------------------------------------------
|
| Bootstrap settings for the CENTRAL platform-operator console. The first-admin
| credentials are read here (config seam, not raw env() in seeders — larastan)
| so the env-gated PlatformAdminSeeder stays opt-in: absent these, it no-ops.
|
*/

return [

    'admin' => [
        'name' => env('PLATFORM_ADMIN_NAME', 'Platform Admin'),
        'email' => env('PLATFORM_ADMIN_EMAIL'),
        'password' => env('PLATFORM_ADMIN_PASSWORD'),
    ],

];
