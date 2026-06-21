<?php

declare(strict_types=1);

return [
    'role' => [
        'owner' => 'Propietario',
        'admin' => 'Administrador',
        'member' => 'Miembro',
    ],
    'title' => 'Miembros',
    'invite' => 'Invitar miembro',
    'invite.name' => 'Nombre',
    'invite.email' => 'Correo electrónico',
    'invite.role' => 'Rol',
    'invite.submit' => 'Enviar invitación',
    'update_role' => 'Actualizar rol',
    'remove' => 'Eliminar',
    'created' => 'Miembro invitado.',
    'updated' => 'Rol actualizado.',
    'removed' => 'Miembro eliminado.',
    'seat' => [
        'unlimited' => 'Asientos ilimitados',
        'remaining' => 'disponibles',
        'used' => 'usados',
    ],
    'error' => [
        'seat_limit' => 'Has alcanzado el límite de asientos de tu plan.',
        'owner_protected' => 'El propietario no puede ser degradado de esta forma.',
        'cannot_remove_owner' => 'No puedes eliminar al único propietario.',
        'cannot_remove_self' => 'No puedes eliminarte a ti mismo.',
        'role_not_assignable' => 'No tienes permiso para asignar ese rol.',
    ],
];
