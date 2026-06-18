<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Data\TenantData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central registration. Anemic: validated DTO in → Action → response.
 */
final class TenantRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Central/Register');
    }

    public function store(CreateTenantData $data, CreateTenant $createTenant): RedirectResponse
    {
        $tenant = $createTenant->handle($data);

        return redirect()
            ->route('tenant.register')
            ->with('tenant', TenantData::fromModel($tenant));
    }
}
