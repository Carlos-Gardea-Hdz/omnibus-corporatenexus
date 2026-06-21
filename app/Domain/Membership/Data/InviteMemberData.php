<?php

declare(strict_types=1);

namespace App\Domain\Membership\Data;

use App\Domain\Membership\Enums\MemberRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\Rules\Unique;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Invite-member payload (slice 002). Spatie Data is the single source of truth:
 * server-side rules AND the generated TS type — no FormRequest, no $request->validate().
 *
 * `email` uniqueness is checked against the `users` table on the TENANT connection
 * (already swapped by InitializeTenancyByDomain by the time the rules run), so an
 * email may exist in many tenants but is unique WITHIN a tenant. The ASSIGNABLE-set
 * check (actor-dependent — an admin may not grant owner) and the SEAT-LIMIT check live
 * in CreateMember, not here, because they depend on the acting user / tenant plan.
 */
#[TypeScript]
final class InviteMemberData extends Data
{
    public function __construct(
        #[Min(2), Max(120)]
        public string $name,
        #[Email, Max(255)]
        public string $email,
        public MemberRole $role = MemberRole::Member,
    ) {}

    /**
     * @return array<string, array<int, EnumRule|Unique>>
     */
    public static function rules(): array
    {
        return [
            'email' => [Rule::unique('users', 'email')],
            'role' => [Rule::enum(MemberRole::class)],
        ];
    }
}
