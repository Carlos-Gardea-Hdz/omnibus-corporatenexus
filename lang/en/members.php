<?php

declare(strict_types=1);

return [
    'role' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'member' => 'Member',
    ],
    'title' => 'Members',
    'invite' => 'Invite member',
    'invite.name' => 'Name',
    'invite.email' => 'Email',
    'invite.role' => 'Role',
    'invite.submit' => 'Send invitation',
    'update_role' => 'Update role',
    'remove' => 'Remove',
    'created' => 'Member invited.',
    'updated' => 'Role updated.',
    'removed' => 'Member removed.',
    'seat' => [
        'unlimited' => 'Unlimited seats',
        'remaining' => 'remaining',
        'used' => 'used',
    ],
    'error' => [
        'seat_limit' => 'You have reached your plan seat limit.',
        'owner_protected' => 'The owner cannot be demoted this way.',
        'cannot_remove_owner' => 'You cannot remove the sole owner.',
        'cannot_remove_self' => 'You cannot remove yourself.',
        'role_not_assignable' => 'You are not allowed to assign that role.',
    ],
];
