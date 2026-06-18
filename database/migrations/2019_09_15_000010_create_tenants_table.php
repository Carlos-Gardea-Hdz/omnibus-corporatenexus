<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CENTRAL migration. The tenant registry lives in the central database only —
 * never inside a tenant DB (multitenancy §1). `id` is a UUIDv7 string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->string('id')->primary(); // UUIDv7

            // Custom columns promoted out of the virtual `data` JSON column.
            $table->string('name');
            $table->string('status')->default('pending')->index();
            $table->string('plan')->default('free')->index();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
