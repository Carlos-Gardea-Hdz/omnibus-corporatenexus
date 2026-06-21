<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Data\UpdateTaskData;
use App\Domain\Work\Exceptions\ProjectArchivedException;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Update a task's title / description / priority / due_date (slice 004). The
 * status change goes through TransitionTask and the assignee through AssignTask
 * — never here. A task on an ARCHIVED project is read-only
 * (ProjectArchivedException).
 */
final class UpdateTask
{
    public function handle(Task $task, UpdateTaskData $data): Task
    {
        if ($task->project->status->isArchived()) {
            throw ProjectArchivedException::make();
        }

        return DB::transaction(function () use ($task, $data): Task {
            $task->fill([
                'title' => $data->title,
                'description' => $data->description,
                'priority' => $data->priority,
                'due_date' => $data->due_date,
            ])->save();

            return $task;
        });
    }
}
