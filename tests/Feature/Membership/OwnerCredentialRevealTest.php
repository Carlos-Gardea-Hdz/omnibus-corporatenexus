<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;

/*
| Single-read owner-credential reveal (F1 + F2, hardened by W2 + W4). The seeder
| parks the owner's one-time temp password on the tenant's CENTRAL `data` column.
|
| W2: the provisioning page AND the reveal endpoint are reachable ONLY behind a
| temporary SIGNED URL — a stranger who guesses the tenant UUID gets a 403 and
| cannot consume the credential.
|
| W4: the credential is NEVER read-and-cleared on a poll tick. The status page
| (`show`) only reports `can_reveal_credential` + a signed reveal URL; the
| plaintext is handed out exactly once by a DELIBERATE call to the dedicated
| reveal endpoint, which is idempotent (a second call returns null, never a 500,
| and never null-overwrites a value mid-render).
|
| This touches only the CENTRAL registry (no tenant DB), so RefreshDatabase is
| safe here. PII (the temp password) never lands in logs — only the sanctioned
| one-shot prop/response.
*/

uses(RefreshDatabase::class);

function signedProvisioningUrl(Tenant $tenant): string
{
    return URL::temporarySignedRoute('central.provisioning', now()->addHours(2), ['tenant' => $tenant]);
}

function signedRevealUrl(Tenant $tenant): string
{
    return URL::temporarySignedRoute('central.provisioning.credential', now()->addHours(2), ['tenant' => $tenant]);
}

it('does not carry the plaintext on the status page; it only signals a reveal is available (W4)', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // The status page never carries the plaintext (it cannot be read-and-cleared
    // on a poll tick), but signals the credential is available to reveal.
    $this->get(signedProvisioningUrl($tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Central/Provisioning')
            ->where('is_active', true)
            ->where('can_reveal_credential', true)
            ->missing('owner_temp_password')
            ->where('reveal_credential_url', fn (?string $url): bool => is_string($url) && str_contains($url, 'signature=')));

    // Rendering the status page did NOT clear the parked credential (no race).
    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBe('S3cretTempP@ss');
});

it('reveals the owner temp password exactly once via the deliberate reveal endpoint, then clears it', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // First deliberate reveal → the plaintext comes back once.
    $this->getJson(signedRevealUrl($tenant))
        ->assertOk()
        ->assertJson(['owner_temp_password' => 'S3cretTempP@ss']);

    // The reveal cleared it from the central registry (no plaintext-forever).
    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBeNull();

    // Raw central row no longer carries the credential in its `data` JSON.
    $data = DB::table('tenants')->where('id', $tenant->getKey())->value('data');
    expect($data)->not->toContain('owner_temp_password');

    // A SECOND reveal (the double-fire / second-poll-tick case) is idempotent:
    // it returns null and does NOT throw — the owner keeps the value they got.
    $this->getJson(signedRevealUrl($tenant))
        ->assertOk()
        ->assertJson(['owner_temp_password' => null]);
});

it('rejects the provisioning page and the reveal endpoint without a valid signature (W2 → 403)', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // Bare (unsigned) URLs — what a stranger guessing the UUID would build.
    $base = 'http://'.config('app.central_domain');

    $this->get($base.'/provisioning/'.$tenant->getKey())->assertForbidden();
    $this->getJson($base.'/provisioning/'.$tenant->getKey().'/credential')->assertForbidden();

    // The credential was NOT consumed by the rejected attempts.
    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBe('S3cretTempP@ss');
});

it('never reveals a temp password while the tenant is still provisioning (pending)', function (): void {
    /** @var Tenant $tenant */
    $tenant = Tenant::factory()->pending()->create([
        'owner_temp_password' => 'S3cretTempP@ss',
    ]);

    // Pending status page → no reveal is offered; the parked value is untouched.
    $this->get(signedProvisioningUrl($tenant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('is_active', false)
            ->where('can_reveal_credential', false)
            ->where('reveal_credential_url', null));

    // Even a deliberate reveal call is a no-op while not servable.
    $this->getJson(signedRevealUrl($tenant))
        ->assertOk()
        ->assertJson(['owner_temp_password' => null]);

    $fresh = Tenant::query()->findOrFail($tenant->getKey());
    expect($fresh->getAttribute('owner_temp_password'))->toBe('S3cretTempP@ss');
});
