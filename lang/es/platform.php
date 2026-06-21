<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Acceso de administrador de plataforma',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'submit' => 'Entrar',
    ],
    'dashboard' => [
        'title' => 'Organizaciones',
        'filters' => [
            'status' => 'Estado',
            'plan' => 'Plan',
        ],
        'empty' => 'Ninguna organización coincide con estos filtros.',
    ],
    'tenant' => [
        'detail_title' => 'Detalle de la organización',
        'owner_email' => 'Correo del propietario',
        'created_at' => 'Creada',
        'seat_limit' => 'Límite de asientos',
        'price' => 'Precio',
        'features' => 'Funciones',
        'over_limit' => 'Sobre el límite de asientos',
    ],
    'actions' => [
        'suspend' => 'Suspender',
        'reactivate' => 'Reactivar',
        'change_plan' => 'Cambiar plan',
        'suspend_success' => 'Organización suspendida.',
        'reactivate_success' => 'Organización reactivada.',
        'plan_success' => 'Plan de la organización actualizado.',
        'illegal_transition' => 'Ese cambio de estado no está permitido.',
    ],
];
