<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Data\UpdateMemberRoleData;
use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Membership\Exceptions\OwnerSingletonException;
use App\Domain\Membership\Exceptions\RoleNotAssignableException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Update a member's role (slice 002, tenant context). Mirrors the CMS owner-singleton
 * logic. Guards run in order, all server-side and actor-dependent:
 *
 *   1. OWNER OWN-RECORD RULE — the owner row may be edited ONLY by itself, and the
 *      owner may NOT demote itself via a plain update (the singleton can only change
 *      hands as a side-effect of promoting someone else). Both violations →
 *      OwnerSingletonException.
 *
 *   2. ASSIGNABLE SET — a non-owner actor may never assign the owner role →
 *      RoleNotAssignableException.
 *
 *   3. OWNER TRANSFER SWAP — promoting some OTHER user to owner demotes the current
 *      owner to admin in the SAME transaction, so exactly one owner remains
 *      (multi-row → DB::transaction).
 *
 * Org/tenant confinement is intrinsic: the target is already a row in this tenant DB.
 * No Illuminate\Http import.
 */
final class UpdateMemberRole
{
    public function handle(UpdateMemberRoleData $data, User $target, User $actor): User
    {
        $this->assertOwnerOwnRecord($data->role, $target, $actor);

        if (! $actor->role->canAssign($data->role)) {
            throw RoleNotAssignableException::make();
        }

        return DB::transaction(function () use ($data, $target): User {
            // Promoting a DIFFERENT user to owner: demote the current owner so exactly
            // one remains after the swap.
            if ($data->role === MemberRole::Owner && $target->role !== MemberRole::Owner) {
                $this->demoteCurrentOwner();
            }

            $target->update(['role' => $data->role]);

            return $target->refresh();
        });
    }

    /**
     * Protect the owner singleton's own record: the sole owner may be edited only by
     * itself, and may not demote itself via a plain update.
     *
     * @throws OwnerSingletonException
     */
    private function assertOwnerOwnRecord(MemberRole $newRole, User $target, User $actor): void
    {
        if ($target->role !== MemberRole::Owner) {
            return;
        }

        if (! $target->is($actor)) {
            throw OwnerSingletonException::make();
        }

        if ($newRole !== MemberRole::Owner) {
            throw OwnerSingletonException::make();
        }
    }

    /**
     * Demote the current owner to admin (the swap's first half). Runs inside the
     * caller's transaction.
     */
    private function demoteCurrentOwner(): void
    {
        User::query()
            ->where('role', MemberRole::Owner->value)
            ->update(['role' => MemberRole::Admin->value]);
    }
}
