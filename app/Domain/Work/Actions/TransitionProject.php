<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Exceptions\InvalidProjectTransitionException;
use App\Domain\Work\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Move a project to a new lifecycle status (slice 004). The ProjectStatus graph
 * is the single authoritative guard — any illegal edge (including any self→self)
 * throws InvalidProjectTransitionException, so the controller never has to know
 * the legality matrix. Archiving is the `→ archived` edge; un-archiving the
 * `archived → active` edge.
 */
final class TransitionProject
{
    public function handle(Project $project, ProjectStatus $target): Project
    {
        if (! $project->status->canTransitionTo($target)) {
            throw InvalidProjectTransitionException::between($project->status, $target);
        }

        return DB::transaction(function () use ($project, $target): Project {
            $project->fill(['status' => $target])->save();

            return $project;
        });
    }
}
