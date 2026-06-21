<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Work\Enums\TaskPriority;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\Exists;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Create-task payload (slice 004). Spatie Data is the single source of truth +
 * generated TS type. A web validation failure resolves into a 302 + session
 * errors (never a 422).
 *
 * The assignee guard is `exists:users,id` — and because the rules run AFTER the
 * tenant connection has been swapped in, "exists in users" means "is a member of
 * THIS tenant". A task therefore CANNOT be assigned to a user id absent from the
 * tenant DB; the cross-tenant member guard is enforced declaratively here.
 *
 * `status` is NOT accepted on create — a new task always starts in `todo`
 * (stamped by CreateTask). `project_id` comes from the route, not the payload.
 * `created_by` is stamped from the acting member.
 */
#[TypeScript]
final class CreateTaskData extends Data
{
    public function __construct(
        #[Min(3), Max(160)]
        public string $title,
        #[Max(2000)]
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Medium,
        public ?int $assigned_to = null,
        #[Date]
        public ?CarbonImmutable $due_date = null,
    ) {}

    /**
     * @return array<string, array<int, EnumRule|Exists|string>>
     */
    public static function rules(): array
    {
        return [
            'priority' => [Rule::enum(TaskPriority::class)],
            'assigned_to' => ['nullable', Rule::exists('users', 'id')],
        ];
    }
}
