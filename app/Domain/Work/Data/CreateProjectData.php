<?php

declare(strict_types=1);

namespace App\Domain\Work\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Create-project payload (slice 004). Spatie Data is the single source of truth:
 * server-side rules AND the generated TS type — no FormRequest, no
 * $request->validate(). A web validation failure resolves through the controller
 * signature into a 302 + session errors (never a 422).
 *
 * `status` is NOT accepted on create — a new project always starts in `planning`
 * (stamped by CreateProject). `created_by` is stamped from the acting member.
 */
#[TypeScript]
final class CreateProjectData extends Data
{
    public function __construct(
        #[Min(3), Max(120)]
        public string $name,
        #[Max(2000)]
        public ?string $description = null,
    ) {}
}
