<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\LlmProvider;
use JsonSerializable;

class LlmProviderPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly LlmProvider $provider
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
            'id' => $this->provider->id,
            'name' => $this->provider->name,
            'adapter' => $this->provider->adapter,
            'url' => $this->provider->url,
            'model' => $this->provider->model_name,
            'has_api_key' => ! empty($this->provider->api_key),
            'options' => $this->provider->options,
            'enabled' => $this->provider->enabled,
            'sort' => $this->provider->sort,
            'created_at' => $this->provider->created_at->toIso8601String(),
            'updated_at' => $this->provider->updated_at?->toIso8601String(),
        ];
    }
}
