<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant dashboard. Inside tenant context, tenant('id') resolves the current
 * tenant. Only DTO-shaped, safe fields are exposed as props (inertia-react §6).
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return Inertia::render('Tenant/Dashboard', [
            'tenant' => TenantData::fromModel($tenant),
        ]);
    }
}
