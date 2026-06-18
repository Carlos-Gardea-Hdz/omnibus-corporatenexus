<?php

declare(strict_types=1);

use App\Domain\Tenancy\Data\CreateTenantData;
use App\Domain\Tenancy\Enums\TenantPlan;
use Illuminate\Support\Facades\Validator;

it('rejects reserved subdomains (default-deny allowlist)', function (): void {
    $validator = Validator::make(
        ['name' => 'Acme', 'subdomain' => 'admin', 'ownerEmail' => 'a@b.com'],
        CreateTenantData::getValidationRules(['name' => 'Acme', 'subdomain' => 'admin', 'ownerEmail' => 'a@b.com']),
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('subdomain'))->toBeTrue();
});

it('accepts a valid payload and defaults to the free plan', function (): void {
    $data = CreateTenantData::from([
        'name' => 'Acme Inc',
        'subdomain' => 'acme',
        'ownerEmail' => 'owner@acme.test',
    ]);

    expect($data->plan)->toBe(TenantPlan::Free)
        ->and($data->subdomain)->toBe('acme');
});
