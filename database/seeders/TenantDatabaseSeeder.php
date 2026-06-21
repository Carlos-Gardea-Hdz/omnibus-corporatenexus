<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\Note;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * TENANT seeder. Runs INSIDE a tenant database via stancl's SeedDatabase job
 * (wired through config/tenancy.php → seeder_parameters['--class']). Because the job
 * runs the seeder inside tenancy()->runForMultiple, tenant() resolves the current
 * tenant WITH its central `data` column — so owner_email (written by CreateTenant) is
 * readable here.
 *
 * Two responsibilities:
 *   1. Seed the FIRST owner user from the signup owner_email (the singleton authority),
 *      with a one-time generated temp password (hashed by the model cast, never
 *      plaintext-persisted in the tenant DB). The plaintext is parked ONCE on the
 *      tenant's CENTRAL `data` column (owner_temp_password) so the provisioning
 *      screen can reveal the credential a single time, then CLEARS it on that first
 *      active render (see ProvisioningStatusController) — so it is never persisted
 *      indefinitely (email delivery deferred).
 *   2. Seed a few fictional demo notes — visible proof of tenant-DB context. All
 *      invented data: never real names/emails/PII (project law).
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedOwner();
        $this->seedDemoNotes();
    }

    /**
     * Create the tenant owner from the central signup owner_email, then surface the
     * one-time temp password on the tenant's central registry record.
     */
    private function seedOwner(): void
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        $temp = Str::password(16);

        $ownerEmail = $tenant->getAttribute('owner_email');
        $ownerEmail = is_string($ownerEmail) ? $ownerEmail : '';

        User::create([
            'name' => 'Owner',
            'email' => mb_strtolower(trim($ownerEmail)),
            'role' => MemberRole::Owner,
            'password' => $temp, // hashed by the model cast
            'email_verified_at' => now(),
        ]);

        // Persist the one-time credential on the tenant's CENTRAL `data` column. This
        // MUST go through a model save (not a query-builder ->update()): the stancl
        // VirtualColumn trait encodes non-custom attributes into `data` only on the
        // `saving` event, so a raw update would target a non-existent column. Reloading
        // on the central connection guarantees the write lands on tenants.data and is
        // readable later by the provisioning screen, regardless of tenant context.
        $centralConnection = config()->string('tenancy.database.central_connection');

        /** @var Tenant $central */
        $central = Tenant::on($centralConnection)->findOrFail($tenant->getKey());
        $central->fill(['owner_temp_password' => $temp])->save();
    }

    private function seedDemoNotes(): void
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
