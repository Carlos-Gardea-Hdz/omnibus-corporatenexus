<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformAdmin>
 *
 * Generates a fictional platform operator — never real PII (project law). The
 * password is hashed; the plaintext default ('password') is test-only.
 */
final class PlatformAdminFactory extends Factory
{
    protected $model = PlatformAdmin::class;

    /**
     * The password shared across generated admins (hashed once per run).
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
