<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Domain\Membership\Actions\CreateMember;
use App\Domain\Membership\Actions\RemoveMember;
use App\Domain\Membership\Actions\UpdateMemberRole;
use App\Domain\Membership\Data\InviteMemberData;
use App\Domain\Membership\Data\UpdateMemberRoleData;
use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Member administration inside tenant context (slice-002 §6, CONTRACT). Gated
 * `auth` upstream in routes/tenant.php; this controller adds the role gate so a
 * `member` hitting store/update/destroy is a 403. Anemic by law (≤15 lines/method):
 * each mutation hands a validated DTO (resolved via the method signature → web
 * failure is 302 + session errors, never 422) plus the acting {@see User} to its
 * Action, which owns the seat-limit check, the assignable-role guard, the
 * owner-singleton swap and the self/owner-removal guards (each surfaced as a 302 +
 * flash/field error by the handlers registered in bootstrap/app.php, never a 500).
 * No `Request`, no `DB` facade here. {user} binds on the tenant connection, so a
 * cross-tenant id 404s.
 */
final class MemberController extends Controller
{
    public function index(): Response
    {
        $actor = $this->actor();
        $seatLimit = $this->seatLimit();
        $seatUsed = User::count();

        $members = User::query()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => $this->mapMember($user, $actor))
            ->all();

        return Inertia::render('Tenant/Members/Index', [
            'members' => $members,
            'seat_limit' => $seatLimit,
            'seat_used' => $seatUsed,
            'seats_remaining' => $seatLimit === 0 ? null : max(0, $seatLimit - $seatUsed),
            'assignable_roles' => $this->assignableRoles($actor),
            'can' => [
                'manage_members' => $actor->role->canManageMembers(),
                'invite' => $actor->role->canManageMembers(),
                'transfer_ownership' => $actor->role === MemberRole::Owner,
            ],
        ]);
    }

    public function store(InviteMemberData $data, CreateMember $action): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanManage($actor);
        $invited = $action->handle($data, $actor);

        return redirect()->route('tenant.members.index')
            ->with('success', __('members.created'))
            ->with('temp_password', $invited->tempPassword);
    }

    public function update(User $user, UpdateMemberRoleData $data, UpdateMemberRole $action): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanManage($actor);
        $action->handle($data, $user, $actor);

        return redirect()->route('tenant.members.index')->with('success', __('members.updated'));
    }

    public function destroy(User $user, RemoveMember $action): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanManage($actor);
        $action->handle($user, $actor);

        return redirect()->route('tenant.members.index')->with('success', __('members.removed'));
    }

    /** The authenticated acting member (the `auth` gate guarantees presence). */
    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, HttpResponse::HTTP_FORBIDDEN);

        return $actor;
    }

    /** A `member` cannot manage members → 403 (gates store/update/destroy). */
    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->role->canManageMembers(), HttpResponse::HTTP_FORBIDDEN);
    }

    /** Seat ceiling from the CENTRAL plan (0 = unlimited). */
    private function seatLimit(): int
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        return $tenant->plan->seatLimit();
    }

    /**
     * The roles an actor may assign (owner: any; admin: admin/member only).
     *
     * @return list<array{value: string, label: string}>
     */
    private function assignableRoles(User $actor): array
    {
        $roles = array_filter(
            MemberRole::cases(),
            fn (MemberRole $role): bool => $actor->role->canAssign($role),
        );

        return array_values(array_map(
            fn (MemberRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            $roles,
        ));
    }

    /**
     * Shape one member row for the index (snake_case contract — NO password/token).
     *
     * @return array<string, mixed>
     */
    private function mapMember(User $user, User $actor): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'is_self' => $user->is($actor),
            'is_owner' => $user->role === MemberRole::Owner,
        ];
    }
}
