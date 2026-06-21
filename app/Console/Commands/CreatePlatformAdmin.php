<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

/**
 * Bootstraps a platform operator for the SaaS console (slice 003, OQ-3). The
 * primary interactive path to mint the FIRST admin (the env-gated
 * PlatformAdminSeeder is the non-interactive sibling). Idempotent on email:
 * re-running for an existing email reports it and exits without a duplicate.
 *
 * Central-only: writes the central `platform_admins` table, never a tenant DB.
 * The password is hashed by the model cast and never echoed.
 */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'platform:create-admin
        {name? : Display name (prompted if omitted)}
        {email? : Login email, unique (prompted if omitted)}
        {password? : Plaintext password (prompted + hidden if omitted)}';

    protected $description = 'Create a platform operator (central SaaS console admin).';

    public function handle(): int
    {
        $name = $this->stringArgument('name') ?? text(
            label: 'Name',
            required: true,
        );

        $email = mb_strtolower(trim($this->stringArgument('email') ?? text(
            label: 'Email',
            required: true,
        )));

        $password = $this->stringArgument('password') ?? promptPassword(
            label: 'Password',
            required: true,
        );

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if (PlatformAdmin::query()->where('email', $email)->exists()) {
            $this->warn("A platform admin with email [{$email}] already exists. Nothing to do.");

            return self::SUCCESS;
        }

        PlatformAdmin::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed by the model cast
        ]);

        $this->info("Platform admin [{$email}] created.");

        return self::SUCCESS;
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
