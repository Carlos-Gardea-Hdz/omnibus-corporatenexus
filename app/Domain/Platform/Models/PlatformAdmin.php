<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use Database\Factories\PlatformAdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Platform operator (the SaaS console admin). Lives in the CENTRAL database
 * ONLY — it is the identity that manages the tenant registry. It is completely
 * separate from a tenant member (App\Models\User on the per-tenant `users`
 * table): a platform admin authenticates against the central `admin` guard and
 * can NEVER become a tenant user, nor vice-versa (slice 003, central↛tenant).
 *
 * The connection is pinned to the configured central connection so a stray
 * tenant context (this slice never enters one) can never swap it to a tenant DB.
 *
 * @property string $id UUIDv7 (chronologically sortable, app-generated)
 * @property string $name
 * @property string $email
 * @property string $password hashed (cast 'hashed'); never logged or propped
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PlatformAdmin extends Authenticatable
{
    /** @use HasFactory<PlatformAdminFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Mint a UUIDv7 primary key on create (project law: UUIDv7 only). Mirrors
     * the tenancy id strategy so central rows are chronologically sortable.
     */
    protected static function booted(): void
    {
        self::creating(function (self $admin): void {
            if ($admin->getKey() === null || $admin->getKey() === '') {
                $admin->setAttribute($admin->getKeyName(), (string) Str::uuid7());
            }
        });
    }

    /**
     * Pin to the CENTRAL connection. Resolved live from config so it follows the
     * deployment's DB_CONNECTION and is immune to tenant connection swapping.
     */
    public function getConnectionName(): string
    {
        return config()->string('tenancy.database.central_connection');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    protected static function newFactory(): PlatformAdminFactory
    {
        return PlatformAdminFactory::new();
    }
}
