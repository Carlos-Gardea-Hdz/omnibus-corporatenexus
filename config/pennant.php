<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Pennant Store
    |--------------------------------------------------------------------------
    |
    | Here you will specify the default store that Pennant should use when
    | storing and resolving feature flag values. Pennant ships with the
    | ability to store flag values in an in-memory array or database.
    |
    | Supported: "array", "database"
    |
    */

    'default' => env('PENNANT_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Pennant Stores
    |--------------------------------------------------------------------------
    |
    | Here you may configure each of the stores that should be available to
    | Pennant. These stores shall be used to store resolved feature flag
    | values - you may configure as many as your application requires.
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
        ],

        'database' => [
            'driver' => 'database',
            // Pin to the CENTRAL connection (W1). A null connection would resolve
            // flags against the tenant-swapped default connection inside tenant
            // context, but the `features` table migration lives ONLY in the central
            // DB (database/migrations), never in tenant DBs — so resolving a flag in
            // a tenant route would throw 42P01 "relation features does not exist".
            // Feature flags are plan-derived central state (multitenancy §4); always
            // store/resolve them on central regardless of the active tenant.
            'connection' => env('DB_CONNECTION', 'central'),
            'table' => 'features',
        ],

    ],
];
