<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\Note;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant landing page. Reached only AFTER tenancy is initialized by the
 * identification middleware, so tenant() resolves the current tenant and the
 * default connection points at the tenant database. notes_count is a live
 * COUNT read inside tenant context — the visible proof of DB isolation
 * (multitenancy §2).
 */
final class LandingController extends Controller
{
    public function index(): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return Inertia::render('Tenant/Landing', [
            'tenant' => TenantData::fromModel($tenant),
            'notes_count' => Note::count(),
        ]);
    }
}
