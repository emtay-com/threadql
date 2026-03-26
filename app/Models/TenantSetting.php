<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantSettingEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantSetting model
 *
 * Represents a configurable setting for a tenant, used for guardrails and feature flags.
 *
 * @property int $id
 * @property int $tenant_id
 * @property TenantSettingEnum $name
 * @property string $value
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class TenantSetting extends Model
{
    use HasFactory;

    protected $table = 'tenant_settings';

    protected $fillable = ['tenant_id', 'name', 'value'];

    protected $casts = [
        'name' => TenantSettingEnum::class,
    ];

    /**
     * Get the tenant that owns this setting.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check if this setting is enabled (value is "1").
     */
    public function isEnabled(): bool
    {
        return $this->value === '1';
    }

    /**
     * Set the setting value to enabled ("1").
     */
    public function setEnabled(): void
    {
        $this->value = '1';
    }
}
