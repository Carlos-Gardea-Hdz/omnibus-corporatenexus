<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 *
 * Generates fictional demo tenants — never seed real client data (project law).
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'status' => TenantStatus::Active,
            'plan' => fake()->randomElement(TenantPlan::cases()),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => TenantStatus::Pending]);
    }

    public function enterprise(): static
    {
        return $this->state(['plan' => TenantPlan::Enterprise]);
    }
}
