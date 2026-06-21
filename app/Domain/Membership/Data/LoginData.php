<?php

declare(strict_types=1);

namespace App\Domain\Membership\Data;

use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Tenant login payload (slice 002). Spatie Data is the single source of validation
 * truth + the generated TS type. The login key is `email` (tenant users are keyed on
 * email). No FormRequest, no $request->validate(). Web validation surfaces as 302 +
 * session errors, never 422.
 */
#[TypeScript]
final class LoginData extends Data
{
    public function __construct(
        #[Required, Email, Max(255)]
        public string $email,
        #[Required, Max(255)]
        public string $password,
        public bool $remember = false,
    ) {}
}
