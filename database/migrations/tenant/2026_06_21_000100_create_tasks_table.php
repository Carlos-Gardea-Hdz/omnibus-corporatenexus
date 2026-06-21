<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration (slice 004). Runs on every tenant database via
 * `php artisan tenants:migrate`. `tasks` is a tenant-OWNED table, isolated by
 * CONNECTION like `projects` (no `tenant_id` column).
 *
 * The PK is a UUIDv7 string. `project_id` is a required UUID FK to `projects.id`
 * (cascadeOnDelete — deleting a project removes its tasks). `assigned_to` and
 * `created_by` are nullable bigint FKs to the tenant `users.id` (nullOnDelete) —
 * the assignee MUST be a member of THIS tenant (enforced declaratively at the
 * DTO layer via `exists:users,id` resolving in tenant context).
 *
 * Sorted AFTER the projects migration so up() creates the FK target first and
 * `tenants:migrate:rollback` drops `tasks` before `projects` — FK-safe both ways.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')
                ->constrained('projects')
                ->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->string('priority', 10)->default('medium');
            $table->foreignId('assigned_to')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            // project_id and assigned_to are already indexed by their FK
            // constraints (foreignUuid/foreignId ->constrained); only the
            // non-FK status filter and the composite board lookup need explicit
            // indexes here.
            $table->index('status');
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
