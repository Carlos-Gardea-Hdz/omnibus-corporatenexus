<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Membership\Exceptions\CannotRemoveOwnerException;
use App\Domain\Membership\Exceptions\CannotRemoveSelfException;
use App\Models\User;

/**
 * Remove a member (slice 002, tenant context). Mirrors the CMS delete logic. Two
 * guards:
 *
 *   1. An actor may not remove THEMSELF (CannotRemoveSelfException) — no actor locks
 *      itself out mid-session.
 *   2. The SOLE owner may not be removed (CannotRemoveOwnerException) — the owner
 *      singleton requires the tenant always retain one.
 *
 * Otherwise the member is HARD-deleted ($target->delete()) — there are no FK
 * dependents this slice, so a single-row delete needs no transaction. No
 * Illuminate\Http import; the guards surface as domain exceptions the HTTP layer
 * renders as a 302 + flash.
 */
final class RemoveMember
{
    public function handle(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw CannotRemoveSelfException::make();
        }

        if ($this->isLastOwner($target)) {
            throw CannotRemoveOwnerException::make();
        }

        $target->delete();
    }

    /**
     * True when the target is an owner and no other owner exists. Excludes the target
     * itself from the survivor count.
     */
    private function isLastOwner(User $target): bool
    {
        if ($target->role !== MemberRole::Owner) {
            return false;
        }

        $others = User::query()
            ->where('role', MemberRole::Owner->value)
            ->whereKeyNot($target->getKey())
            ->count();

        return $others === 0;
    }
}
