<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantSettingEnum;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Tenant model
 *
 * Represents a multi-tenant organization with its own
 * LLM provider configuration and API credentials.
 *
 * @property int $id
 * @property string $name
 * @property string|null $bot_name
 * @property string $uuid
 * @property string $timezone
 * @property string|null $slack_app_id
 * @property string|null $slack_client_id
 * @property string|null $slack_bot_token
 * @property string|null $slack_signing_secret
 * @property string|null $slack_verification_token
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Collection<int, LlmProvider> $llmProviders
 * @property-read Collection<int, Datasource> $datasources
 * @property-read Collection<int, Table> $tables
 * @property-read Collection<int, Query> $queries
 * @property-read Collection<int, Feedback> $feedback
 * @property-read Collection<int, Definition> $definitions
 * @property-read Collection<int, TenantSetting> $settings
 * @property-read Collection<int, User> $users
 */
class Tenant extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'tenants';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'bot_name',
        'uuid',
        'timezone',
        'slack_app_id',
        'slack_client_id',
        'slack_bot_token',
        'slack_signing_secret',
        'slack_verification_token',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['slack_bot_token', 'slack_signing_secret', 'slack_verification_token'];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) Str::uuid();
            }
        });
    }

    // Slack credential encryption accessors/mutators

    public function setSlackAppIdAttribute(?string $value): void
    {
        $this->attributes['slack_app_id'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSlackAppIdAttribute(?string $value): ?string
    {
        return is_null($value) ? null : Crypt::decryptString($value);
    }

    public function setSlackClientIdAttribute(?string $value): void
    {
        $this->attributes['slack_client_id'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSlackClientIdAttribute(?string $value): ?string
    {
        return is_null($value) ? null : Crypt::decryptString($value);
    }

    public function setSlackBotTokenAttribute(?string $value): void
    {
        $this->attributes['slack_bot_token'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSlackBotTokenAttribute(?string $value): ?string
    {
        return is_null($value) ? null : Crypt::decryptString($value);
    }

    public function setSlackSigningSecretAttribute(?string $value): void
    {
        $this->attributes['slack_signing_secret'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSlackSigningSecretAttribute(?string $value): ?string
    {
        return is_null($value) ? null : Crypt::decryptString($value);
    }

    public function setSlackVerificationTokenAttribute(?string $value): void
    {
        $this->attributes['slack_verification_token'] = is_null($value) ? null : Crypt::encryptString($value);
    }

    public function getSlackVerificationTokenAttribute(?string $value): ?string
    {
        return is_null($value) ? null : Crypt::decryptString($value);
    }

    /**
     * Get the route key name.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Get the LLM providers for this tenant.
     */
    public function llmProviders(): HasMany
    {
        return $this->hasMany(LlmProvider::class);
    }

    /**
     * Get the datasources for this tenant.
     */
    public function datasources(): HasMany
    {
        return $this->hasMany(Datasource::class);
    }

    /**
     * Get the tables for this tenant.
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    /**
     * Get the queries for this tenant.
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /**
     * Get the feedback for this tenant.
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    /**
     * Get the definitions for this tenant.
     */
    public function definitions(): HasMany
    {
        return $this->hasMany(Definition::class);
    }

    /**
     * Get the Slack users for this tenant.
     */
    public function slackUsers(): HasMany
    {
        return $this->hasMany(SlackUser::class);
    }

    /**
     * Get the settings for this tenant.
     */
    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    /**
     * Get a tenant setting by name, creating it with the default value if it doesn't exist.
     *
     * @param  TenantSettingEnum  $setting  The setting to retrieve.
     */
    public function getSetting(TenantSettingEnum $setting): TenantSetting
    {
        /** @var TenantSetting $tenantSetting */
        $tenantSetting = $this->settings()
            ->firstOrCreate(
                        [
                            'name' => $setting,
                        ],
                        [
                            'value' => (string) config("tenant-settings.defaults.{$setting->value}"),
                        ]
                    );

        return $tenantSetting;
    }

    /**
     * Get the admin users for this tenant.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get the primary datasource for this tenant.
     */
    public function primaryDatasource(): ?Datasource
    {
        /** @var Datasource|null $datasource */
        $datasource = $this->datasources()
            ->orderBy('id')
            ->first();

        return $datasource;
    }
}
