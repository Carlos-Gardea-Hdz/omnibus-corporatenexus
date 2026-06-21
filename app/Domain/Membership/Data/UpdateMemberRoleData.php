<?php

declare(strict_types=1);

namespace App\Domain\Membership\Data;

use App\Domain\Membership\Enums\MemberRole;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Update-member-role payload (slice 002). The role is the only mutable field this
 * slice — name/email changes are deferred. Spatie Data is the single source of
 * truth + generated TS type. The owner-singleton swap and assignable-set guards live
 * in UpdateMemberRole, not here (they depend on actor + target).
 */
#[TypeScript]
final class UpdateMemberRoleData extends Data
{
    public function __construct(
        public MemberRole $role,
    ) {}

    /**
     * @return array<string, array<int, EnumRule>>
     */
    public static function rules(): array
    {
        return [
            'role' => [Rule::enum(MemberRole::class)],
        ];
    }
}
