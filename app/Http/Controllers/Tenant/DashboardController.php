<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Tenant dashboard, behind `auth` (slice-002 §6). Inside tenant context tenant()
 * resolves the current tenant and User::count() reads the tenant DB. Only safe,
 * snake_case props are exposed — never the raw model (inertia-react §6). The
 * seat limit comes from the CENTRAL plan (tenant()->plan), never a tenant column.
 */
final class DashboardController extends Controller
{
    public function index(): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        $authUser = request()->user();
        abort_unless($authUser instanceof User, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('Tenant/Dashboard', [
            'tenant' => TenantData::fromModel($tenant),
            'auth_user' => [
                'id' => $authUser->id,
                'name' => $authUser->name,
                'email' => $authUser->email,
                'role' => $authUser->role->value,
            ],
            'member_count' => User::count(),
            'seat_limit' => $tenant->plan->seatLimit(),
        ]);
    }
}
