<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant note. Lives in the TENANT database (its migration is under
 * database/migrations/tenant). Inside tenant context the default connection is
 * swapped to the tenant DB by the tenancy bootstrappers, so a plain Eloquent
 * model is automatically isolated by connection — no `tenant_id` scope needed.
 *
 * Minimal on purpose: it exists to prove DB-per-tenant isolation
 * (CrossTenantIsolationTest), not as a real business domain.
 */
final class Note extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'body',
    ];
}
