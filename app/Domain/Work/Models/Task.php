<?php

declare(strict_types=1);

namespace App\Domain\Work\Models;

use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use App\Models\User;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A tenant-owned task (slice 004). Lives in the TENANT database alongside
 * `projects`; isolated by connection like Project (no `tenant_id` column).
 *
 * The primary key is a UUIDv7. `project_id` is a required UUID FK (cascade on
 * delete). `assigned_to` and `created_by` are nullable bigint FKs to the tenant
 * `users.id`; the assignee MUST be a member of THIS tenant (enforced at the DTO
 * layer via `exists:users,id` resolving in tenant context — a task cannot be
 * assigned to a user id absent from the tenant DB).
 *
 * @property string $id
 * @property string $project_id
 * @property string $title
 * @property string|null $description
 * @property TaskStatus $status
 * @property TaskPriority $priority
 * @property int|null $assigned_to
 * @property Carbon|null $due_date
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Project $project
 * @property-read User|null $assignee
 */
final class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
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
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'due_date',
        'created_by',
    ];

    /** UUIDv7 for the key — chronologically sortable (program law). */
    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'due_date' => 'date',
        ];
    }
}
