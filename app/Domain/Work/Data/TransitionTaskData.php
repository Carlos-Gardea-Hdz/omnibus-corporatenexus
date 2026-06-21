<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Work\Enums\TaskStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transition-task payload (slice 004). The target status is validated as a legal
 * TaskStatus here; whether the CURRENT→target edge is legal is the authority of
 * TaskStatus::canTransitionTo() in TransitionTask (an illegal edge throws
 * InvalidTaskTransitionException → 302 + flash, never a 422/500).
 */
#[TypeScript]
final class TransitionTaskData extends Data
{
    public function __construct(
        public TaskStatus $status,
    ) {}

    /**
     * @return array<string, array<int, EnumRule>>
     */
    public static function rules(): array
    {
        return [
            'status' => [Rule::enum(TaskStatus::class)],
        ];
    }
}
