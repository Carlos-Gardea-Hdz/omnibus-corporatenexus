<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Data\PlanOptionData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central registration. Anemic: validated DTO in → Action → redirect to the
 * provisioning-status page. Runs against the CENTRAL database only; never
 * enters tenant context (multitenancy §1).
 */
final class TenantRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Central/Register', [
            'plans' => PlanOptionData::collect(
                array_map(PlanOptionData::fromEnum(...), TenantPlan::cases()),
            ),
        ]);
    }

    public function store(CreateTenantData $data, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle($data);

        return redirect()->route('central.provisioning', $tenant);
    }
}
