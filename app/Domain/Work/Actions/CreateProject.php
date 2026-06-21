<?php

declare(strict_types=1);

namespace App\Domain\Work\Actions;

use App\Domain\Work\Data\CreateProjectData;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create a tenant project (slice 004). Runs in tenant context, so the write
 * lands on the TENANT `projects` table. A new project always starts in
 * `planning`; `created_by` is stamped from the acting member. No Illuminate\Http
 * import.
 */
final class CreateProject
{
    public function handle(CreateProjectData $data, User $actor): Project
    {
        return DB::transaction(fn (): Project => Project::create([
            'name' => $data->name,
            'description' => $data->description,
            'status' => ProjectStatus::Planning,
            'created_by' => $actor->id,
        ]));
    }
}
