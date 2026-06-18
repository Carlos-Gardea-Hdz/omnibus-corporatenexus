<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Output contract for a tenant. Only safe fields are exposed to Inertia
 * props — never spread the raw model (inertia-react §6). Generates the TS
 * `TenantData` type consumed by the React shell.
 */
#[TypeScript]
final class TenantData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public TenantStatus $status,
        public TenantPlan $plan,
    ) {}

    public static function fromModel(Tenant $tenant): self
    {
        return new self(
            id: $tenant->id,
            name: $tenant->name,
            status: $tenant->status,
            plan: $tenant->plan,
        );
    }
}
