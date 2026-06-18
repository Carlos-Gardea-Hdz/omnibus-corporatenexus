<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Events\TenantCreated;
use App\Domain\Tenancy\Exceptions\TenantProvisioningException;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('provisions a pending central tenant with a UUIDv7 id and domain', function (): void {
    Event::fake([TenantCreated::class]);

    $tenant = app(CreateTenant::class)->handle(new CreateTenantData(
        name: 'Acme Inc',
        subdomain: 'acme',
        ownerEmail: 'owner@acme.test',
        plan: TenantPlan::Business,
    ));

    expect($tenant)->toBeInstanceOf(Tenant::class)
        ->and($tenant->status)->toBe(TenantStatus::Pending)
        ->and($tenant->plan)->toBe(TenantPlan::Business)
        ->and($tenant->getKey())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/');

    $this->assertDatabaseHas('domains', ['domain' => 'acme.'.config('app.central_domain')]);

    Event::assertDispatched(TenantCreated::class);
});

it('rejects a subdomain that is already taken', function (): void {
    $data = new CreateTenantData('Acme', 'acme', 'owner@acme.test');

    app(CreateTenant::class)->handle($data);

    expect(fn () => app(CreateTenant::class)->handle($data))
        ->toThrow(TenantProvisioningException::class);
});
