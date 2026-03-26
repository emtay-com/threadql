<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * LLM Provider model
 *
 * Represents different LLM providers (OpenAI, Anthropic, Ollama, etc.)
 * with their configuration details.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $adapter
 * @property string|null $url
 * @property string $model_name
 * @property string|null $api_key
 * @property array<string, mixed>|null $options
 * @property bool $enabled
 * @property int $sort
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 */
class LlmProvider extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'llm_providers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'adapter',
        'url',
        'model_name',
        'api_key',
        'options',
        'tenant_id',
        'enabled',
        'sort',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['api_key'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'enabled' => 'boolean',
        'options' => 'array',
    ];

    // API key encryption accessors/mutators

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getApiKeyAttribute(): ?string
    {
        if (is_null($this->attributes['api_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($this->attributes['api_key']);
        } catch (DecryptException) {
        }

        return null;
    }

    /**
     * Get the tenant that owns this LLM provider.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
