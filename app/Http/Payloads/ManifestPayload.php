<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use JsonSerializable;

/**
 * Payload for Slack App Manifest response.
 *
 * Contains the manifest as escaped JSON string for frontend display.
 */
class ManifestPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param string $manifestJson The raw manifest JSON string
     */
    public function __construct(
        private readonly string $manifestJson
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
     * Get the array representation.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'manifest' => $this->manifestJson,
        ];
    }
}
