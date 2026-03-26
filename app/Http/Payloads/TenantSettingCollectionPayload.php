<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\TenantSetting;
use JsonSerializable;

class TenantSettingCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param  array<int, TenantSetting>  $settings
     */
    public function __construct(
        private readonly array $settings
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
            'data' => array_map(
                fn (TenantSetting $setting) => (new TenantSettingPayload($setting))->toArray(),
                $this->settings
            ),
            'meta' => [
                'total' => count($this->settings),
            ],
        ];
    }
}
