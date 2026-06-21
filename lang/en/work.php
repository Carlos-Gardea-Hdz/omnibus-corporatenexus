<?php

declare(strict_types=1);

return [
    // Backed-enum labels — resolved by ProjectStatus/TaskStatus/TaskPriority label().
    'project_status' => [
        'planning' => 'Planning',
        'active' => 'Active',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ],
    'task_status' => [
        'todo' => 'To do',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],
    'task_priority' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    // Project flash messages (controller redirects).
    'project' => [
        'created' => 'Project created.',
        'updated' => 'Project updated.',
        'transitioned' => 'Project status updated.',
    ],

    // Task flash messages (controller redirects).
    'task' => [
        'created' => 'Task created.',
        'updated' => 'Task updated.',
        'transitioned' => 'Task status updated.',
        'assigned' => 'Task assignment updated.',
    ],

    // Domain exception flashes (302, never 500).
    'errors' => [
        'invalid_project_transition' => 'That project status change is not allowed.',
        'invalid_task_transition' => 'That task status change is not allowed.',
        'project_archived' => 'This project is archived; its tasks are read-only.',
    ],

    // Page / UI copy.
    'projects' => [
        'title' => 'Projects',
        'subtitle' => "Organize your team's work into projects and tasks.",
        'total' => 'Total',
        'empty' => 'No projects yet.',
        'tasks' => 'Tasks',
        'open_tasks' => 'Open',
        'back' => 'Back to projects',
    ],
    'board' => [
        'column_empty' => 'No tasks in this column.',
    ],
    'filters' => [
        'all' => 'All',
        'apply' => 'Filter',
        'clear' => 'Clear',
    ],
    'saving' => 'Saving…',
];
