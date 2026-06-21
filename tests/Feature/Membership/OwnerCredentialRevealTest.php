<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Single-read owner-credential reveal (F1 + F2). The seeder parks the owner's
| one-time temp password on the tenant's CENTRAL `data` column. The provisioning
| status page must:
|   1. surface that plaintext ONCE — on the first ACTIVE render (F2: deliver it);
|   2. immediately CLEAR it from the registry so it is never persisted forever
|      and a SECOND visit reveals nothing (F1: plaintext-forever leak closed).
|
| This touches only the CENTRAL registry (no tenant DB), so RefreshDatabase is
| safe here. PII (the temp password) never lands in logs — only the sanctioned
| one-shot prop.
*/

uses(RefreshDatabase::class);

function provisioningUrl(Tenant $tenant): string
{
    return 'http://'.config('app.central_domain').'/provisioning/'.$tenant->getKey();
}

it('reveals the owner temp password exactly once, then clears it from the registry', function (): void {
    // An ACTIVE tenant whose central data carries the parked one-time password.
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // It is parked on the central `data` column (not a custom column).
    expect($tenant->getAttribute('owner_temp_password'))->toBe('S3cretTempP@ss');

    // First render (tenant is active) → the prop carries the plaintext once.
    $this->get(provisioningUrl($tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Provisioning')
            ->where('is_active', true)
            ->where('owner_temp_password', 'S3cretTempP@ss'));

    // The reveal cleared it from the central registry (no plaintext-forever).
    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBeNull();

    // Raw central row no longer carries the credential in its `data` JSON.
    $data = DB::table('tenants')->where('id', $tenant->getKey())->value('data');
    expect($data)->not->toContain('owner_temp_password');

    // Second render → the prop is now null (one-shot window is closed).
    $this->get(provisioningUrl($tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Provisioning')
            ->where('owner_temp_password', null));
});

it('never reveals a temp password while the tenant is still provisioning (pending)', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->pending()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // Pending render → prop is null AND the parked value is left untouched
    // (still available for the eventual first active render).
    $this->get(provisioningUrl($tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('is_active', false)
            ->where('owner_temp_password', null));

    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBe('S3cretTempP@ss');
});
