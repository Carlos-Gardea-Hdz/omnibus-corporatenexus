<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Data\AssignTaskData;
use App\Domain\Work\Exceptions\ProjectArchivedException;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * (Re)assign or unassign a task (slice 004). `assigned_to` null = unassign.
 *
 * The assignee-is-a-member guard already ran at the DTO layer
 * (`exists:users,id` in tenant context), so a cross-tenant id never reaches the
 * Action — a task cannot be assigned to a user id absent from THIS tenant's DB.
 * The Action enforces the one precondition the DTO cannot express: a task on an
 * ARCHIVED project is read-only (ProjectArchivedException).
 */
final class AssignTask
{
    public function handle(Task $task, AssignTaskData $data): Task
    {
        if ($task->project->status->isArchived()) {
            throw ProjectArchivedException::make();
        }

        return DB::transaction(function () use ($task, $data): Task {
            $task->fill(['assigned_to' => $data->assigned_to])->save();

            return $task;
        });
    }
}
