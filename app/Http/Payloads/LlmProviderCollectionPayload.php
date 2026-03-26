<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\LlmProvider;
use JsonSerializable;

class LlmProviderCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, LlmProvider> $providers
     */
    public function __construct(
        private readonly array $providers
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
                fn (LlmProvider $provider) => (new LlmProviderPayload($provider))->toArray(),
                $this->providers
            ),
            'meta' => [
                'total' => count($this->providers),
            ],
        ];
    }
}
