<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Tenancy\Data\TenantData;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant landing page. Reached only AFTER tenancy is initialized by the
 * identification middleware, so tenant() resolves the current tenant and the
 * default connection points at the tenant database. projects_count and
 * open_tasks_count are live COUNTs read inside tenant context — the visible
 * proof of DB isolation (multitenancy §2), replacing the lifecycle-demo
 * notes_count (slice-004 §C11).
 */
final class LandingController extends Controller
{
    public function index(): Response
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return Inertia::render('Tenant/Landing', [
            'tenant' => TenantData::fromModel($tenant),
            'projects_count' => Project::count(),
            'open_tasks_count' => Task::query()->where('status', '!=', TaskStatus::Done->value)->count(),
        ]);
    }
}
