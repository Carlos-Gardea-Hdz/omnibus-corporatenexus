<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration (slice 004). Runs on every tenant database via
 * `php artisan tenants:migrate`. `projects` is a tenant-OWNED table — a row
 * written in tenant A's database is physically absent from tenant B's database.
 *
 * No `tenant_id` column on purpose: isolation here is by CONNECTION (separate
 * physical database), not a shared-schema discriminator.
 *
 * The PK is a UUIDv7 string (program law: chronologically sortable). `created_by`
 * is a nullable bigint FK to the tenant `users.id` (tenant users keep a bigint PK
 * by prior decision, slice 002) — nullOnDelete so removing a member never
 * cascade-deletes their projects. This migration sorts BEFORE the tasks
 * migration so up() creates `projects` first (FK target) and rollback drops
 * `tasks` first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('planning');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
