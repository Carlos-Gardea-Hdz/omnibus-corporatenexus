<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Membership\Enums\MemberRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Per-tenant user. Lives in the tenant database (the users migration is under
 * database/migrations/tenant). Inside tenant context the default connection is
 * swapped to the tenant DB by the tenancy bootstrappers, so the default `web`
 * guard authenticates against the TENANT users table — the per-tenant DB is the
 * cross-tenant auth isolation (a user of A is physically absent from B's DB).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property MemberRole $role
 * @property Carbon|null $email_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'role',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => MemberRole::class,
        ];
    }
}
