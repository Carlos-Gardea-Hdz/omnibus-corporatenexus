<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;

/**
 * Generates UUIDv7 tenant ids (chronologically sortable, better index
 * locality than v4). Project law: UUIDv7 only.
 *
 * Wired via config/tenancy.php → 'id_generator'.
 */
final class Uuid7Generator implements UniqueIdentifierGenerator
{
    public static function generate(mixed $resource): string
    {
        return (string) Str::uuid7();
    }
}
