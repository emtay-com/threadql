<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Datasource model
 *
 * Represents a read-only database connection for a tenant
 * with connection details and query guardrails.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $label
 * @property string $dsn
 * @property array|null $allowed_schemas_json
 * @property int $default_limit
 * @property int $query_timeout_seconds
 * @property string $timezone
 * @property bool $use_ssh
 * @property string|null $ssh_host
 * @property int|null $ssh_port
 * @property string|null $ssh_username
 * @property string|null $ssh_password
 * @property string|null $ssh_private_key
 * @property string|null $ssh_public_key
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Datasource extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'datasources';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'tenant_id',
        'label',
        'dsn',
        'allowed_schemas_json',
        'default_limit',
        'query_timeout_seconds',
        'timezone',
        'use_ssh',
        'ssh_host',
        'ssh_port',
        'ssh_username',
        'ssh_password',
        'ssh_private_key',
        'ssh_public_key',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['dsn', 'ssh_password', 'ssh_private_key'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'allowed_schemas_json' => 'array',
        'default_limit' => 'integer',
        'query_timeout_seconds' => 'integer',
        'use_ssh' => 'boolean',
        'ssh_port' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this datasource.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function setDsnAttribute(?string $value): void
    {
        $this->attributes['dsn'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getDsnAttribute(): ?string
    {
        if (is_null($this->attributes['dsn'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['dsn']);
        } catch (DecryptException) {
        }

        return null;
    }

    public function setSshPasswordAttribute(?string $value): void
    {
        $this->attributes['ssh_password'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSshPasswordAttribute(): ?string
    {
        if (empty($this->attributes['ssh_password'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['ssh_password']);
        } catch (DecryptException) {
        }

        return null;
    }

    public function setSshPrivateKeyAttribute(?string $value): void
    {
        $this->attributes['ssh_private_key'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSshPrivateKeyAttribute(): ?string
    {
        if (empty($this->attributes['ssh_private_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['ssh_private_key']);
        } catch (DecryptException) {
        }

        return null;
    }
}
