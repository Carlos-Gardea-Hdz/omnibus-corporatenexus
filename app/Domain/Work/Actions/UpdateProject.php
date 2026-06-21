<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Data\UpdateProjectData;
use App\Domain\Work\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Update a tenant project's name + description (slice 004). The status change is
 * NOT handled here — it goes through TransitionProject (the lifecycle guard).
 */
final class UpdateProject
{
    public function handle(Project $project, UpdateProjectData $data): Project
    {
        return DB::transaction(function () use ($project, $data): Project {
            $project->fill([
                'name' => $data->name,
                'description' => $data->description,
            ])->save();

            return $project;
        });
    }
}
