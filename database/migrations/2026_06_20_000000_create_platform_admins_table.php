<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL migration (lives in database/migrations, NOT tenant/). Creates the
 * platform operators table — the identity for the SaaS console that manages the
 * tenant registry (slice 003). It exists only in the central database and is
 * entirely separate from the per-tenant `users` table (central↛tenant).
 *
 * `id` is an app-generated UUIDv7 string (project law: UUIDv7 only), mirroring
 * the tenancy id strategy. Reversible: down() drops the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table): void {
            $table->string('id')->primary(); // UUIDv7
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
