<?php

declare(strict_types=1);

namespace App\Domain\Membership\Data;

use App\Domain\Membership\Enums\MemberRole;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Output contract for a tenant member. Only safe fields reach Inertia props —
 * NEVER the password or remember_token (inertia-react §6). Generates the TS
 * `MemberData` type consumed by the React member roster.
 */
#[TypeScript]
final class MemberData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public MemberRole $role,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            role: $user->role,
        );
    }
}
