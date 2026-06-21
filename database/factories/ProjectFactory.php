<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 *
 * Generates fictional projects inside a tenant database. Only ever invoked
 * within tenant context (the `projects` table exists only in tenant DBs).
 * Fictional data only — never real client content (project law). `created_by`
 * defaults to null; tests/seeders set it to a seeded member id when needed.
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => rtrim(fake()->unique()->sentence(3), '.'),
            'description' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(ProjectStatus::cases()),
            'created_by' => null,
        ];
    }

    /** A project in the initial `planning` status. */
    public function planning(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Planning,
        ]);
    }

    /** An `active` project. */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Active,
        ]);
    }

    /** An `archived` (read-only for tasks) project. */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProjectStatus::Archived,
        ]);
    }
}
