<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Database\Seeder;

/**
 * CENTRAL seeder: bootstraps the FIRST platform operator for the SaaS console
 * (slice 003). Opt-in and idempotent (OQ-3): a no-op unless BOTH
 * PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD are set, so it never mints an
 * admin unconditionally. firstOrCreate keys on email; the password is hashed by
 * the model cast. For interactive use prefer the `platform:create-admin` command.
 */
final class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('platform.admin.email');
        $password = config('platform.admin.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            return; // Opt-in only — no credentials configured, no admin.
        }

        $name = config('platform.admin.name');

        PlatformAdmin::query()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            [
                'name' => is_string($name) && $name !== '' ? $name : 'Platform Admin',
                'password' => $password, // hashed by the model cast
            ],
        );
    }
}
