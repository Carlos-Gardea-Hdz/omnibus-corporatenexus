<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Note;
use Illuminate\Database\Seeder;

/**
 * TENANT seeder. Runs INSIDE a tenant database via stancl's SeedDatabase job
 * (wired through config/tenancy.php → seeder_parameters['--class']).
 *
 * Inserts a few fictional demo notes so a freshly provisioned tenant lands with
 * visible content — the Tenant/Landing page reads this COUNT as live proof of
 * tenant-DB context. Entirely invented data: never real names/emails/PII
 * (project law).
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demoNotes = [
            ['title' => 'Welcome to your workspace', 'body' => 'This is your private, isolated space.'],
            ['title' => 'Getting started', 'body' => 'Invite your team and start collaborating.'],
            ['title' => 'Your data is isolated', 'body' => 'Notes here live in your own database.'],
        ];

        foreach ($demoNotes as $note) {
            Note::create($note);
        }
    }
}
