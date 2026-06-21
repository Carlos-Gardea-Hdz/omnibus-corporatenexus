<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration. Runs on every tenant database via `php artisan tenants:migrate`.
 *
 * `notes` is a minimal tenant-OWNED table whose only purpose (for now) is to be
 * the canonical proof that DB-per-tenant isolation holds: a row written in
 * tenant A's database is physically absent from tenant B's database — exercised
 * by tests/Feature/Tenancy/CrossTenantIsolationTest.php (multitenancy §5).
 *
 * There is no `tenant_id` column on purpose: isolation here is by CONNECTION
 * (separate physical database), not by a shared-schema discriminator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
