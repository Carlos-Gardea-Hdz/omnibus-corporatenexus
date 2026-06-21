<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Membership\Enums\MemberRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Work\Enums\ProjectStatus;
use App\Domain\Work\Enums\TaskPriority;
use App\Domain\Work\Enums\TaskStatus;
use App\Domain\Work\Models\Project;
use App\Domain\Work\Models\Task;
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
 *   2. Seed fictional demo WORK (a couple of Projects, each with a few Tasks) —
 *      visible proof of tenant-DB context for the slice-004 Work domain, and the
 *      data behind the landing projects_count / open_tasks_count. The owner is
 *      attributed as created_by/assigned_to so every FK resolves inside THIS
 *      tenant. All invented data: never real names/emails/PII (project law).
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $owner = $this->seedOwner();
        $this->seedDemoWork($owner);
    }

    /**
     * Create the tenant owner from the central signup owner_email, then surface the
     * one-time temp password on the tenant's central registry record.
     */
    private function seedOwner(): User
    {
        /** @var Tenant $tenant */
        $tenant = tenant();

        $temp = Str::password(16);

        $ownerEmail = $tenant->getAttribute('owner_email');
        $ownerEmail = is_string($ownerEmail) ? $ownerEmail : '';

        $owner = User::create([
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

        return $owner;
    }

    /**
     * Seed fictional demo Work (slice 004) inside the tenant DB: two projects,
     * each with a few tasks across statuses/priorities. The owner is attributed
     * as created_by/assigned_to so every FK resolves to a member of THIS tenant.
     * All invented data — never real names/content (project law).
     */
    private function seedDemoWork(User $owner): void
    {
        $ownerId = $owner->id;

        $onboarding = Project::create([
            'name' => 'Onboarding',
            'description' => 'Get the workspace ready for your team.',
            'status' => ProjectStatus::Active,
            'created_by' => $ownerId,
        ]);

        $roadmap = Project::create([
            'name' => 'Q1 Roadmap',
            'description' => 'Plan the first quarter of work.',
            'status' => ProjectStatus::Planning,
            'created_by' => $ownerId,
        ]);

        $demoTasks = [
            [
                'project_id' => $onboarding->getKey(),
                'title' => 'Invite your teammates',
                'description' => 'Send invitations to the rest of the team.',
                'status' => TaskStatus::InProgress,
                'priority' => TaskPriority::High,
                'assigned_to' => $ownerId,
            ],
            [
                'project_id' => $onboarding->getKey(),
                'title' => 'Set up your first project',
                'description' => 'Create a project and add a few tasks.',
                'status' => TaskStatus::Done,
                'priority' => TaskPriority::Medium,
                'assigned_to' => $ownerId,
            ],
            [
                'project_id' => $onboarding->getKey(),
                'title' => 'Explore the task board',
                'description' => 'Drag tasks across columns to update status.',
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Low,
                'assigned_to' => null,
            ],
            [
                'project_id' => $roadmap->getKey(),
                'title' => 'Draft quarterly goals',
                'description' => 'Outline the objectives for the quarter.',
                'status' => TaskStatus::Todo,
                'priority' => TaskPriority::Urgent,
                'assigned_to' => $ownerId,
            ],
        ];

        foreach ($demoTasks as $task) {
            Task::create([
                ...$task,
                'created_by' => $ownerId,
            ]);
        }
    }
}
