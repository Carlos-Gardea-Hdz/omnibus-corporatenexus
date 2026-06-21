<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Tenant provisioning is QUEUED
|--------------------------------------------------------------------------
| In production the TenantCreated → CreateDatabase + MigrateDatabase pipeline
| runs on a queue worker (see TenancyServiceProvider): PostgreSQL forbids
| CREATE DATABASE inside the central transaction. With QUEUE_CONNECTION=sync a
| queued job would otherwise run inline inside that transaction and fail, so we
| fake the queue by default. Tests that need a real tenant database (the
| cross-tenant isolation suite) provision it explicitly, outside any
| transaction, and opt out of this fake.
*/
pest()->beforeEach(function (): void {
    Queue::fake();
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations & Helpers
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));
