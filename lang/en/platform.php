<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Platform admin sign in',
        'email' => 'Email',
        'password' => 'Password',
        'submit' => 'Sign in',
    ],
    'dashboard' => [
        'title' => 'Tenants',
        'filters' => [
            'status' => 'Status',
            'plan' => 'Plan',
        ],
        'empty' => 'No tenants match these filters.',
    ],
    'tenant' => [
        'detail_title' => 'Tenant detail',
        'owner_email' => 'Owner email',
        'created_at' => 'Created',
        'seat_limit' => 'Seat limit',
        'price' => 'Price',
        'features' => 'Features',
        'over_limit' => 'Over seat limit',
    ],
    'actions' => [
        'suspend' => 'Suspend',
        'reactivate' => 'Reactivate',
        'change_plan' => 'Change plan',
        'suspend_success' => 'Tenant suspended.',
        'reactivate_success' => 'Tenant reactivated.',
        'plan_success' => 'Tenant plan updated.',
        'illegal_transition' => 'That status change is not allowed.',
    ],
];
