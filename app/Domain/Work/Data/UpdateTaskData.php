<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Work\Enums\TaskPriority;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Update-task payload (slice 004). Title / description / priority / due_date
 * only. The status change goes through the transition endpoint
 * (TransitionTaskData) and the assignee change through the assign endpoint
 * (AssignTaskData) — never here. Spatie Data is the single source of truth +
 * generated TS type.
 */
#[TypeScript]
final class UpdateTaskData extends Data
{
    public function __construct(
        #[Min(3), Max(160)]
        public string $title,
        #[Max(2000)]
        public ?string $description = null,
        public TaskPriority $priority = TaskPriority::Medium,
        #[Date]
        public ?CarbonImmutable $due_date = null,
    ) {}

    /**
     * @return array<string, array<int, EnumRule>>
     */
    public static function rules(): array
    {
        return [
            'priority' => [Rule::enum(TaskPriority::class)],
        ];
    }
}
