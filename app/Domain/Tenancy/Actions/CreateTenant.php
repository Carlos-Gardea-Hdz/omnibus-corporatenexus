<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Events\TenantCreated;
use App\Domain\Tenancy\Exceptions\TenantProvisioningException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Provision a new tenant.
 *
 * One business operation. Wrapped in a central-DB transaction because it
 * touches the tenants + domains tables (project law). The tenant database is
 * created + migrated asynchronously by the stancl job pipeline registered in
 * TenancyServiceProvider, so this Action stays off the slow path; we only
 * commit the central registry record here (multitenancy §1, §3).
 *
 * The full subdomain host is derived from CENTRAL_DOMAIN — never trusted from
 * the client (multitenancy §2: never trust client-supplied tenant identity).
 */
final readonly class CreateTenant
{
    public function handle(CreateTenantData $data): Tenant
    {
        $centralDomain = config()->string('app.central_domain');
        $host = $data->subdomain.'.'.$centralDomain;
        $centralConnection = config()->string('tenancy.database.central_connection');

        return DB::connection($centralConnection)
            ->transaction(function () use ($data, $host): Tenant {
                if (Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $host))->exists()) {
                    throw TenantProvisioningException::subdomainTaken($data->subdomain);
                }

                /** @var Tenant $tenant */
                $tenant = Tenant::create([
                    'name' => $data->name,
                    'status' => TenantStatus::Pending,
                    'plan' => $data->plan,
                    // Stored transparently in the virtual `data` column.
                    'owner_email' => $data->ownerEmail,
                ]);

                $tenant->domains()->create(['domain' => $host]);

                TenantCreated::dispatch($tenant);

                return $tenant;
            });
    }
}
