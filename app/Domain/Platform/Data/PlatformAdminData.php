<?php

declare(strict_types=1);

namespace App\Domain\Platform\Data;

use App\Domain\Platform\Models\PlatformAdmin;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Output contract for the authenticated platform admin (the console shell).
 * Only safe identity fields reach Inertia props — NEVER the password or
 * remember_token (inertia-react §6). Generates the TS `PlatformAdminData` type.
 */
#[TypeScript]
final class PlatformAdminData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {}

    public static function fromModel(PlatformAdmin $admin): self
    {
        return new self(
            id: $admin->id,
            name: $admin->name,
            email: $admin->email,
        );
    }
}
