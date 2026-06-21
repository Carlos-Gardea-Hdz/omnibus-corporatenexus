<?php

declare(strict_types=1);

use App\Domain\Membership\Enums\MemberRole;

/*
| Pure enum contract for MemberRole (CONTRACT §2). No DB, no __() resolution
| here beyond the label() key shape — these are Unit tests. label() is asserted
| to point at the members.role.* keys (resolution itself lives in the Feature
| LangResolutionTest, which boots the app and the translator).
*/

it('is a backed string enum with exactly owner/admin/member', function (): void {
    expect(MemberRole::Owner->value)->toBe('owner')
        ->and(MemberRole::Admin->value)->toBe('admin')
        ->and(MemberRole::Member->value)->toBe('member');

    $values = array_map(fn (MemberRole $r): string => $r->value, MemberRole::cases());
    expect($values)->toBe(['owner', 'admin', 'member']);
});

it('exposes a strict privilege ladder via level()', function (): void {
    expect(MemberRole::Owner->level())->toBe(3)
        ->and(MemberRole::Admin->level())->toBe(2)
        ->and(MemberRole::Member->level())->toBe(1);

    // Strictly descending — owner outranks admin outranks member.
    expect(MemberRole::Owner->level())->toBeGreaterThan(MemberRole::Admin->level())
        ->and(MemberRole::Admin->level())->toBeGreaterThan(MemberRole::Member->level());
});

it('answers hasAtLeast() against the level ladder', function (): void {
    expect(MemberRole::Owner->hasAtLeast(MemberRole::Admin))->toBeTrue()
        ->and(MemberRole::Owner->hasAtLeast(MemberRole::Owner))->toBeTrue()
        ->and(MemberRole::Admin->hasAtLeast(MemberRole::Admin))->toBeTrue()
        ->and(MemberRole::Admin->hasAtLeast(MemberRole::Member))->toBeTrue()
        ->and(MemberRole::Member->hasAtLeast(MemberRole::Member))->toBeTrue()
        // A lower role never satisfies a higher minimum.
        ->and(MemberRole::Member->hasAtLeast(MemberRole::Admin))->toBeFalse()
        ->and(MemberRole::Admin->hasAtLeast(MemberRole::Owner))->toBeFalse()
        ->and(MemberRole::Member->hasAtLeast(MemberRole::Owner))->toBeFalse();
});

it('gates member management at admin and above (canManageMembers)', function (): void {
    expect(MemberRole::Owner->canManageMembers())->toBeTrue()
        ->and(MemberRole::Admin->canManageMembers())->toBeTrue()
        ->and(MemberRole::Member->canManageMembers())->toBeFalse();
});

it('enforces the role-assignment matrix via canAssign()', function (): void {
    // Owner may assign any role.
    expect(MemberRole::Owner->canAssign(MemberRole::Owner))->toBeTrue()
        ->and(MemberRole::Owner->canAssign(MemberRole::Admin))->toBeTrue()
        ->and(MemberRole::Owner->canAssign(MemberRole::Member))->toBeTrue();

    // Admin may assign admin/member but NEVER owner.
    expect(MemberRole::Admin->canAssign(MemberRole::Admin))->toBeTrue()
        ->and(MemberRole::Admin->canAssign(MemberRole::Member))->toBeTrue()
        ->and(MemberRole::Admin->canAssign(MemberRole::Owner))->toBeFalse();

    // Member may assign nothing.
    expect(MemberRole::Member->canAssign(MemberRole::Owner))->toBeFalse()
        ->and(MemberRole::Member->canAssign(MemberRole::Admin))->toBeFalse()
        ->and(MemberRole::Member->canAssign(MemberRole::Member))->toBeFalse();
});

it('routes label() through the members.role.* translation keys', function (): void {
    // The translator returns the key itself when no line exists in the test
    // locale set; the point here is the KEY SHAPE (resolution is asserted in the
    // Feature LangResolutionTest). label() must derive from ->value.
    expect(MemberRole::Owner->label())->toBe(__('members.role.owner'))
        ->and(MemberRole::Admin->label())->toBe(__('members.role.admin'))
        ->and(MemberRole::Member->label())->toBe(__('members.role.member'));
});
