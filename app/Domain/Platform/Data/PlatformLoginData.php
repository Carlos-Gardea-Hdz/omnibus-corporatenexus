<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Platform-admin login payload (central console, slice 003). Spatie Data is the
 * single source of validation truth + the generated TS type. Web validation
 * surfaces as 302 + session errors, never 422. No FormRequest.
 */
#[TypeScript]
final class PlatformLoginData extends Data
{
    public function __construct(
        #[Required, Email, Max(255)]
        public string $email,
        #[Required, Max(255)]
        public string $password,
        public bool $remember = false,
    ) {}
}
