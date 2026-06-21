<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use Illuminate\Database\Seeder;

/**
 * CENTRAL seeder. Provisions fictional demo tenants in the tenant registry.
 *
 * Uses entirely invented data — never real client names/emails (project law).
 * Per-tenant data is seeded separately inside tenant context (tenants:seed).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $createTenant = app(CreateTenant::class);

        $demo = [
            ['name' => 'Acme Robotics', 'subdomain' => 'acme', 'plan' => TenantPlan::Business],
            ['name' => 'Globex Labs', 'subdomain' => 'globex', 'plan' => TenantPlan::Enterprise],
            ['name' => 'Initech Studio', 'subdomain' => 'initech', 'plan' => TenantPlan::Team],
        ];

        foreach ($demo as $entry) {
            $createTenant->handle(new CreateTenantData(
                name: $entry['name'],
                subdomain: $entry['subdomain'],
                ownerEmail: "owner@{$entry['subdomain']}.example",
                plan: $entry['plan'],
            ));
        }

        // Opt-in: mints the first platform operator only when env-gated (OQ-3).
        $this->call(PlatformAdminSeeder::class);
    }
}
