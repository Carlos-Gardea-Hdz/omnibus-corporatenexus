<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TENANT migration. Adds the per-tenant member role to the tenant `users` table
 * (slice 002). Runs on every tenant database via `php artisan tenants:migrate`.
 *
 * The PK stays `bigint id` (accepted, spec §10): tenant users never leave their
 * own DB, so a sortable UUID buys nothing here. Reversible.
 *
 * W2 — owner-singleton DB invariant. A PARTIAL UNIQUE INDEX over `role` filtered
 * to `role = 'owner'` makes "at most one owner" a database-enforced truth, so no
 * future code path (a buggy promote, a concurrent write) can ever leave two
 * owners in a tenant. The owner-transfer swap stays valid because it demotes the
 * current owner BEFORE promoting the successor, each as its own statement:
 * Postgres checks the index at statement end, so the demote (0 owners) clears
 * before the promote (1 owner) lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('member')->after('email');
        });

        // Partial unique index: enforce the owner singleton at the DB level.
        DB::statement("CREATE UNIQUE INDEX users_single_owner ON users (role) WHERE role = 'owner'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_single_owner');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
