<?php

declare(strict_types=1);

namespace App\Domain\Work\Models;

use App\Domain\Work\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A tenant-owned project (slice 004). Lives in the TENANT database — its
 * migration is under database/migrations/tenant — so inside tenant context the
 * default connection is swapped to the tenant DB by the tenancy bootstrappers
 * and a plain Eloquent model is automatically isolated by connection. There is
 * no `tenant_id` column: isolation is by CONNECTION (separate physical
 * database), so a project of tenant A is physically absent from tenant B's DB.
 *
 * The primary key is a UUIDv7 (program law: chronologically sortable, better
 * index locality than v4). `created_by` is a nullable bigint FK to the tenant
 * `users.id` (tenant users keep a bigint PK by prior decision, slice 002).
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property ProjectStatus $status
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Task> $tasks
 */
final class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
    ];

    /** UUIDv7 for the key — chronologically sortable (program law). */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
        ];
    }
}
