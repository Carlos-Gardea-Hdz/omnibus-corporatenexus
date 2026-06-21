<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Data\CreateTaskData;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Exceptions\ProjectArchivedException;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a task under a project (slice 004). A new task always starts in `todo`;
 * `created_by` is stamped from the acting member.
 *
 * Guards:
 *   - Archived project is read-only for tasks → ProjectArchivedException
 *     (the one precondition the DTO cannot express).
 *   - The assignee-is-a-member guard already ran at the DTO layer
 *     (`exists:users,id` in tenant context), so no extra query is needed here —
 *     same-connection writes cannot stamp a cross-tenant assignee.
 */
final class CreateTask
{
    public function handle(Project $project, CreateTaskData $data, User $actor): Task
    {
        if ($project->status->isArchived()) {
            throw ProjectArchivedException::make();
        }

        return DB::transaction(fn (): Task => $project->tasks()->create([
            'title' => $data->title,
            'description' => $data->description,
            'status' => TaskStatus::Todo,
            'priority' => $data->priority,
            'assigned_to' => $data->assigned_to,
            'due_date' => $data->due_date,
            'created_by' => $actor->id,
        ]));
    }
}
