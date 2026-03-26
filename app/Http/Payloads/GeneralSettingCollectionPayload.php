<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\GeneralSetting;
use JsonSerializable;

class GeneralSettingCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param  array<int, GeneralSetting>  $settings
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
                fn (GeneralSetting $setting) => (new GeneralSettingPayload($setting))->toArray(),
                $this->settings
            ),
            'meta' => [
                'total' => count($this->settings),
            ],
        ];
    }
}
