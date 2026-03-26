<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\TenantSetting;
use JsonSerializable;

class TenantSettingPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly TenantSetting $setting
    ) {
    }

    /**
     * Serialize the payload to JSON.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->toArray(),
        ];
    }

    /**
     * Convert the payload to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->setting->id,
            'tenant_id' => $this->setting->tenant_id,
            'name' => $this->setting->name->value,
            'value' => $this->setting->value,
            'created_at' => $this->setting->created_at->toIso8601String(),
        ];
    }
}
