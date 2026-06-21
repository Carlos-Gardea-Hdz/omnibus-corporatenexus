<?php

declare(strict_types=1);

namespace App\Domain\Membership\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Per-tenant membership role (slice 002, tenant context). A strict 3-level ladder:
 * owner (3) > admin (2) > member (1). Lives in the TENANT database via the
 * `role` column; backed enum, no magic strings.
 *
 * Assignment authority is asymmetric (canAssign): an owner may grant any role, an
 * admin may grant admin/member but NEVER owner (no privilege escalation toward the
 * singleton), a member may grant nothing.
 */
#[TypeScript]
enum MemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** Ladder rank — higher number = more privilege. */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /** Translated label (members.role.owner|admin|member). */
    public function label(): string
    {
        return __('members.role.'.$this->value);
    }

    /** Ladder gate: does this role meet or exceed the required minimum? */
    public function hasAtLeast(self $minimum): bool
    {
        return $this->level() >= $minimum->level();
    }

    /** Only admins and owners may manage the member roster. */
    public function canManageMembers(): bool
    {
        return $this->hasAtLeast(self::Admin);
    }

    /**
     * May this role assign the given target role?
     *   owner  ⇒ any role (incl. owner, via the transfer swap)
     *   admin  ⇒ admin / member only (NEVER owner)
     *   member ⇒ nothing
     */
    public function canAssign(self $target): bool
    {
        return match ($this) {
            self::Owner => true,
            self::Admin => $target !== self::Owner,
            self::Member => false,
        };
    }
}
