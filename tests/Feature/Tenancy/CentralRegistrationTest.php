<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenantCreated;

/*
| Central registration boundary. These run against the CENTRAL database only
| (no tenant context). RefreshDatabase keeps the central registry clean; the
| QUEUED provisioning pipeline (CreateDatabase + migrate + seed + activate) is
| faked here — Pest.php applies Queue::fake() to every Feature test — so no
| physical tenant DB is created on the signup path. The pipeline itself is
| exercised end-to-end on real PostgreSQL in ProvisioningPipelineTest.
|
| WEB validation returns 302 + session errors, NEVER 422 (Spatie Data via the
| controller signature).
*/

uses(RefreshDatabase::class);

/*
| Real-DB tenancy suites (no RefreshDatabase) commit central registry rows and
| clean them in their own beforeEach, so a row can survive to the next test.
| RefreshDatabase only triggers its one-time migrate:fresh for the FIRST such
| test in the run; if a RefreshDatabase test elsewhere already consumed it, a
| committed leftover is visible (inside this test's transaction) and would skew
| the exact tenant-count assertions below. Clear the central registry up front so
| these counts are deterministic regardless of suite ordering; the transaction
| still rolls everything back afterwards.
*/
beforeEach(function (): void {
    DB::table('domains')->delete();
    DB::table('tenants')->delete();
});

function registerUrl(): string
{
    return 'http://'.config('app.central_domain').'/register';
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Wayne Enterprises',
        'subdomain' => 'wayne',
        'ownerEmail' => 'owner@wayne.test',
        'plan' => 'team',
    ], $overrides);
}

it('renders the central registration page with the plan options', function (): void {
    $this->get(registerUrl())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Central/Register'));
});

it('provisions a pending tenant and redirects to the provisioning status page (302, never 422)', function (): void {
    Event::fake([TenantCreated::class]);

    $response = $this->post(registerUrl(), validPayload());

    // 302 — never a 422 JSON validation response on the web path.
    $response->assertStatus(302);
    $response->assertSessionHasNoErrors();

    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('name', 'Wayne Enterprises')->firstOrFail();

    // Central registry row created in Pending; provisioning has not run yet.
    expect($tenant->status)->toBe(TenantStatus::Pending);

    $response->assertRedirect(route('central.provisioning', $tenant));

    $this->assertDatabaseHas('domains', [
        'domain' => 'wayne.'.config('app.central_domain'),
    ]);

    // The stancl provisioning pipeline is triggered (queued in prod).
    Event::assertDispatched(TenantCreated::class);
});

it('rejects every reserved subdomain at the HTTP boundary (302 + session error, 0 tenants)', function (string $reserved): void {
    $this->post(registerUrl(), validPayload(['subdomain' => $reserved]))
        ->assertStatus(302)
        ->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'www', 'app', 'admin', 'api', 'mail', 'central', 'nexus',
    'dashboard', 'billing', 'support', 'status', 'assets',
    'static', 'cdn', 'blog', 'help', 'docs', 'internal',
]);

it('rejects malformed subdomains (302 + session error, 0 tenants)', function (string $subdomain): void {
    $this->post(registerUrl(), validPayload(['subdomain' => $subdomain]))
        ->assertStatus(302)
        ->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(0);
})->with([
    'too short' => 'a',                                   // < 2 chars
    'too long' => str_repeat('a', 64),                    // > 63 chars
    'uppercase' => 'WayneCorp',                           // not lowercase
    'illegal char' => 'wayne_corp!',                      // not AlphaDash
    'spaces' => 'wayne corp',                             // not AlphaDash
]);

it('rejects a duplicate subdomain (302 + session error, exactly one owner)', function (): void {
    // First registration claims the host.
    $this->post(registerUrl(), validPayload(['subdomain' => 'acme', 'name' => 'Acme One']))
        ->assertStatus(302)
        ->assertSessionHasNoErrors();

    // Second registration for the same subdomain is rejected.
    $this->post(registerUrl(), validPayload(['subdomain' => 'acme', 'name' => 'Acme Two']))
        ->assertStatus(302)
        ->assertSessionHasErrors('subdomain');

    // Exactly one tenant owns the host.
    $host = 'acme.'.config('app.central_domain');
    expect(Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', $host))->count())->toBe(1)
        ->and(Tenant::query()->count())->toBe(1);
});

it('rejects an invalid owner email (302 + session error)', function (): void {
    $this->post(registerUrl(), validPayload(['ownerEmail' => 'not-an-email']))
        ->assertStatus(302)
        ->assertSessionHasErrors('ownerEmail');

    expect(Tenant::query()->count())->toBe(0);
});
