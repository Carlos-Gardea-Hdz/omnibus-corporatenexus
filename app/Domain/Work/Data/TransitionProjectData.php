<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use App\Domain\Work\Enums\ProjectStatus;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Transition-project payload (slice 004). The target status is validated as a
 * legal ProjectStatus here; whether the CURRENT→target edge is legal is the
 * authority of ProjectStatus::canTransitionTo() in TransitionProject (an illegal
 * edge throws InvalidProjectTransitionException → 302 + flash, never a 422/500).
 */
#[TypeScript]
final class TransitionProjectData extends Data
{
    public function __construct(
        public ProjectStatus $status,
    ) {}

    /**
     * @return array<string, array<int, EnumRule>>
     */
    public static function rules(): array
    {
        return [
            'status' => [Rule::enum(ProjectStatus::class)],
        ];
    }
}
