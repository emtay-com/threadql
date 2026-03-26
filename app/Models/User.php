<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int $tenant_id
 * @property string $username
 * @property UserLevel $level
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class User extends Authenticatable implements JWTSubject
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password', 'tenant_id', 'username', 'level'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'level' => UserLevel::class,
    ];

    /**
     * Get the tenant this user belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Determine if the user is a master-level user.
     */
    public function isMaster(): bool
    {
        return $this->level === UserLevel::MASTER;
    }

    /**
     * Get the identifier stored in the subject claim of the JWT.
     *
     * @return int|string
     */
    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Return custom claims for this user token.
     *
     * @return array<string, mixed>
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'is_master' => $this->isMaster(),
            'level' => $this->level->value,
            'tenant_id' => $this->tenant_id,
            'username' => $this->username,
        ];
    }
}
