<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Pennant\Concerns\HasFeatures;
use Laravel\Pennant\Contracts\FeatureScopeable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Central tenant registry record.
 *
 * Lives ONLY in the central database. Billing, plan and registry metadata
 * are stored here and must never be duplicated into a tenant database —
 * deleting a tenant DB must not destroy billing/audit data (multitenancy §1).
 *
 * Implements FeatureScopeable so Pennant flags scope to the tenant, not the
 * user, and serialize correctly for the `database` driver (multitenancy §4).
 *
 * @property string $id UUIDv7 (chronologically sortable)
 * @property string $name
 * @property TenantStatus $status
 * @property TenantPlan $plan
 *
 * @method \Illuminate\Database\Eloquent\Relations\HasMany<\Stancl\Tenancy\Database\Models\Domain, self> domains()
 */
final class Tenant extends BaseTenant implements FeatureScopeable, TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasFeatures;

    /**
     * Columns promoted out of the virtual `data` JSON column into real,
     * indexable columns. Everything else is transparently stored in `data`.
     *
     * @return array<int, string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'plan',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'plan' => TenantPlan::class,
        ];
    }

    /**
     * Pennant scope identifier — serialize the tenant by its id so flags
     * resolve correctly across requests and the database store.
     */
    public function toFeatureIdentifier(mixed $driver): mixed
    {
        return $this->getKey();
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }
}
