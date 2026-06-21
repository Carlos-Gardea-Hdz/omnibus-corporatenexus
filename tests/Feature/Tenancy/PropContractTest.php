<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Database\Models\Domain;

/*
| Inertia prop CONTRACTS for the two CENTRAL pages (snake_case props, exact
| shapes per CONTRACT §10.1 and §10.2). These run against the central DB only,
| so RefreshDatabase + a factory tenant is enough — no physical tenant DB is
| provisioned (the Tenant/Landing contract, which needs tenant context, is
| asserted in TenantIdentificationTest on real PostgreSQL).
|
| Inertia props are snake_case; enum-backed props serialize to their string
| value.
*/

uses(RefreshDatabase::class);

it('Central/Register exposes the exact plan-option prop shape', function (): void {
    $this->get('http://'.config('app.central_domain').'/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Register')
            ->has('plans', count(TenantPlan::cases()), fn ($plan) => $plan
                ->has('value')
                ->has('label')
                ->has('price_cents')
                ->has('seat_limit')
                ->etc()
            )
        );
});

it('Central/Register plan options carry integer cents and the correct values', function (): void {
    $this->get('http://'.config('app.central_domain').'/register')
        ->assertInertia(function ($page): void {
            /** @var array<int, array<string, mixed>> $plans */
            $plans = $page->toArray()['props']['plans'];

            $values = array_column($plans, 'value');
            expect($values)->toContain(TenantPlan::Free->value, TenantPlan::Team->value, TenantPlan::Business->value, TenantPlan::Enterprise->value);

            foreach ($plans as $plan) {
                expect($plan['price_cents'])->toBeInt()
                    ->and($plan['seat_limit'])->toBeInt();
            }

            $team = collect($plans)->firstWhere('value', TenantPlan::Team->value);
            expect($team['price_cents'])->toBe(2900);
        });
});

it('Central/Provisioning exposes tenant, tenant_url and is_active (pending → null url, not active)', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->pending()->create(['name' => 'Provisioning Co']);
    Domain::create([
        'domain' => 'prov.'.config('app.central_domain'),
        'tenant_id' => $tenant->getKey(),
    ]);

    // Reachable only behind a temporary SIGNED URL (W2).
    $this->get(URL::temporarySignedRoute('central.provisioning', now()->addHour(), ['tenant' => $tenant]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Provisioning')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.status', TenantStatus::Pending->value)
            ->where('tenant.plan', $tenant->plan->value)
            ->where('is_active', false)
            ->where('tenant_url', null)
            ->has('tenant.name')
            // PII guard: the owner email lives in the central tenants.data column and
            // must NEVER reach a client prop (a future careless TenantData change can't leak it).
            ->missing('tenant.owner_email')
            ->missing('tenant.ownerEmail')
        );
});

it('Central/Provisioning yields a tenant_url and is_active=true once the tenant is Active', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create(['name' => 'Live Co', 'status' => TenantStatus::Active]);
    $host = 'live.'.config('app.central_domain');
    Domain::create(['domain' => $host, 'tenant_id' => $tenant->getKey()]);

    // Reachable only behind a temporary SIGNED URL (W2).
    $this->get(URL::temporarySignedRoute('central.provisioning', now()->addHour(), ['tenant' => $tenant]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Provisioning')
            ->where('is_active', true)
            ->where('tenant_url', 'https://'.$host)
        );
});
