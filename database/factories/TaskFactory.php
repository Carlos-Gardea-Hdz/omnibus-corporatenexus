<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 *
 * Generates fictional tasks inside a tenant database. Only ever invoked within
 * tenant context (the `tasks` table exists only in tenant DBs). Fictional data
 * only — never real client content (project law). Default `assigned_to` is null
 * (unassigned); `forProject()` / `assignedTo()` wire the relations explicitly so
 * the assignee is always a member of THIS tenant.
 */
final class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(TaskStatus::cases()),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'assigned_to' => null,
            'due_date' => fake()->optional()->dateTimeBetween('now', '+2 months'),
            'created_by' => null,
        ];
    }

    /** Attach the task to an existing project. */
    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes): array => [
            'project_id' => $project->id,
        ]);
    }

    /** Assign the task to a tenant member (by id). */
    public function assignedTo(int $userId): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to' => $userId,
        ]);
    }

    /** A task in the initial `todo` status. */
    public function todo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TaskStatus::Todo,
        ]);
    }
}
