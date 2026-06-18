<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the central registration page', function (): void {
    $this->get('http://'.config('app.central_domain').'/register')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Central/Register'));
});

it('provisions a tenant through the central registration endpoint', function (): void {
    $response = $this->post('http://'.config('app.central_domain').'/register', [
        'name' => 'Wayne Enterprises',
        'subdomain' => 'wayne',
        'ownerEmail' => 'owner@wayne.test',
        'plan' => 'team',
    ]);

    $response->assertRedirect();

    expect(Tenant::query()->where('name', 'Wayne Enterprises')->exists())->toBeTrue();
    $this->assertDatabaseHas('domains', ['domain' => 'wayne.'.config('app.central_domain')]);
});

it('rejects a reserved subdomain at the HTTP boundary', function (): void {
    $this->post('http://'.config('app.central_domain').'/register', [
        'name' => 'Bad Actor',
        'subdomain' => 'admin',
        'ownerEmail' => 'a@b.test',
        'plan' => 'free',
    ])->assertSessionHasErrors('subdomain');

    expect(Tenant::query()->count())->toBe(0);
});
