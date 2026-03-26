<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\GeneralSetting;
use JsonSerializable;

class GeneralSettingPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly GeneralSetting $setting
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
            'setting' => $this->setting->setting->value,
            'value' => $this->setting->value,
            'created_at' => $this->setting->created_at->toIso8601String(),
            'updated_at' => $this->setting->updated_at->toIso8601String(),
        ];
    }
}
