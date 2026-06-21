<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-tenant note. Lives in the TENANT database (its migration is under
 * database/migrations/tenant). Inside tenant context the default connection is
 * swapped to the tenant DB by the tenancy bootstrappers, so a plain Eloquent
 * model is automatically isolated by connection — no `tenant_id` scope needed.
 *
 * Minimal on purpose: it exists to prove DB-per-tenant isolation
 * (CrossTenantIsolationTest) and to back the Tenant/Landing notes_count proof,
 * not as a real business domain.
 *
 * @property int $id
 * @property string $title
 * @property string|null $body
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'body',
    ];

    protected static function newFactory(): NoteFactory
    {
        return NoteFactory::new();
    }
}
