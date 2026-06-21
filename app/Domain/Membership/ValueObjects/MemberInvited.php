<?php

declare(strict_types=1);

namespace App\Domain\Membership\ValueObjects;

use App\Models\User;

/**
 * Result of inviting a member (slice 002). Carries the freshly created tenant User
 * plus the ONE-TIME generated temporary password — the only sanctioned moment a
 * credential leaves the Action, surfaced once via a flash message by the controller
 * (email delivery deferred). The temp password is NEVER persisted in plaintext nor
 * placed on a serialized prop.
 */
final readonly class MemberInvited
{
    public function __construct(
        public User $user,
        public string $tempPassword,
    ) {}
}
