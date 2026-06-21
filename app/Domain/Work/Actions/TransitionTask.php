<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Exceptions\InvalidTaskTransitionException;
use App\Domain\Work\Exceptions\ProjectArchivedException;
use App\Domain\Work\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Move a task to a new lifecycle status (slice 004). Two guards run before any
 * write: the parent project must not be archived (ProjectArchivedException), and
 * the TaskStatus graph is the single authoritative guard for the edge — any
 * illegal edge (including any self→self) throws InvalidTaskTransitionException.
 */
final class TransitionTask
{
    public function handle(Task $task, TaskStatus $target): Task
    {
        if ($task->project->status->isArchived()) {
            throw ProjectArchivedException::make();
        }

        if (! $task->status->canTransitionTo($target)) {
            throw InvalidTaskTransitionException::between($task->status, $target);
        }

        return DB::transaction(function () use ($task, $target): Task {
            $task->fill(['status' => $target])->save();

            return $task;
        });
    }
}
