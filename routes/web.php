<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| Routing is split by tenancy boundary and registered explicitly in
| bootstrap/app.php:
|
|   routes/central.php  → central app (registration, billing, admin).
|   routes/tenant.php   → tenant app, behind tenancy initialization +
|                         PreventAccessFromCentralDomains.
|
| This file is intentionally left empty. Do NOT add tenant-aware routes here.
*/
