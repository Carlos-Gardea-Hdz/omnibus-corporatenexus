<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 *
 * Generates fictional notes inside a tenant database. Only ever invoked within
 * tenant context (the `notes` table exists only in tenant DBs). Fictional data
 * only — never real client content (project law).
 */
final class NoteFactory extends Factory
{
    protected $model = Note::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'body' => fake()->optional()->paragraph(),
        ];
    }
}
