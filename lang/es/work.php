<?php

declare(strict_types=1);

return [
    // Etiquetas de los enums respaldados — resueltas por label() de ProjectStatus/TaskStatus/TaskPriority.
    'project_status' => [
        'planning' => 'Planificación',
        'active' => 'Activo',
        'completed' => 'Completado',
        'archived' => 'Archivado',
    ],
    'task_status' => [
        'todo' => 'Por hacer',
        'in_progress' => 'En progreso',
        'done' => 'Hecho',
    ],
    'task_priority' => [
        'low' => 'Baja',
        'medium' => 'Media',
        'high' => 'Alta',
        'urgent' => 'Urgente',
    ],

    // Mensajes flash de proyecto (redirecciones del controlador).
    'project' => [
        'created' => 'Proyecto creado.',
        'updated' => 'Proyecto actualizado.',
        'transitioned' => 'Estado del proyecto actualizado.',
    ],

    // Mensajes flash de tarea (redirecciones del controlador).
    'task' => [
        'created' => 'Tarea creada.',
        'updated' => 'Tarea actualizada.',
        'transitioned' => 'Estado de la tarea actualizado.',
        'assigned' => 'Asignación de la tarea actualizada.',
    ],

    // Flashes de excepciones de dominio (302, nunca 500).
    'errors' => [
        'invalid_project_transition' => 'Ese cambio de estado del proyecto no está permitido.',
        'invalid_task_transition' => 'Ese cambio de estado de la tarea no está permitido.',
        'project_archived' => 'Este proyecto está archivado; sus tareas son de solo lectura.',
    ],

    // Copia de páginas / interfaz.
    'projects' => [
        'title' => 'Proyectos',
        'subtitle' => 'Organiza el trabajo de tu equipo en proyectos y tareas.',
        'total' => 'Total',
        'empty' => 'Aún no hay proyectos.',
        'tasks' => 'Tareas',
        'open_tasks' => 'Abiertas',
        'back' => 'Volver a proyectos',
    ],
    'board' => [
        'column_empty' => 'Sin tareas en esta columna.',
    ],
    'filters' => [
        'all' => 'Todas',
        'apply' => 'Filtrar',
        'clear' => 'Limpiar',
    ],
    'saving' => 'Guardando…',
];
