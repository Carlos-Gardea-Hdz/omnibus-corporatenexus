<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a tenant row + domain are committed in the central DB.
 * Heavy follow-up work (DB creation, migrations, seeding) is queued by the
 * stancl job pipeline — keep listeners off the request cycle (multitenancy §3).
 */
final class TenantCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Tenant $tenant,
    ) {}
}
