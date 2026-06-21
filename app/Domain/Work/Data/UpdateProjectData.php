<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Update-project payload (slice 004). Name + description only; the status change
 * goes through the dedicated transition endpoint (TransitionProjectData), never
 * here. Spatie Data is the single source of truth + generated TS type.
 */
#[TypeScript]
final class UpdateProjectData extends Data
{
    public function __construct(
        #[Min(3), Max(120)]
        public string $name,
        #[Max(2000)]
        public ?string $description = null,
    ) {}
}
