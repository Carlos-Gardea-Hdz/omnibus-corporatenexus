<?php

declare(strict_types=1);

namespace App\Domain\Membership\Actions;

use App\Domain\Membership\Data\InviteMemberData;
use App\Domain\Membership\Exceptions\RoleNotAssignableException;
use App\Domain\Membership\Exceptions\SeatLimitExceededException;
use App\Domain\Membership\ValueObjects\MemberInvited;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invite (create) a tenant member (slice 002). Runs in tenant context, so every query
 * and write lands on the TENANT `users` table.
 *
 * Two server-side, actor/plan-dependent guards run BEFORE any write:
 *
 *   1. SEAT LIMIT — `tenant()->plan->seatLimit()` (0 = unlimited). If the limit is
 *      positive and the current member count already meets it, throw
 *      SeatLimitExceededException. Checked first so a full tenant fails cheaply.
 *
 *   2. ASSIGNABLE SET — the new role must be one the actor may grant (an admin may not
 *      mint an owner). Violations throw RoleNotAssignableException.
 *
 * The member is created with a one-time generated temp password (hashed by the model
 * cast, NEVER logged); it is returned in the MemberInvited VO for a single flash
 * reveal. No Illuminate\Http import.
 */
final class CreateMember
{
    public function handle(InviteMemberData $data, User $actor): MemberInvited
    {
        /** @var Tenant $tenant */
        $tenant = tenant();
        $limit = $tenant->plan->seatLimit();

        if ($limit > 0 && User::query()->count() >= $limit) {
            throw SeatLimitExceededException::make();
        }

        if (! $actor->role->canAssign($data->role)) {
            throw RoleNotAssignableException::make();
        }

        return DB::transaction(function () use ($data): MemberInvited {
            $temp = Str::password(16);

            $user = User::create([
                'name' => $data->name,
                'email' => mb_strtolower(trim($data->email)),
                'role' => $data->role,
                'password' => $temp, // hashed by the model cast; never logged
            ]);

            return new MemberInvited($user, $temp);
        });
    }
}
