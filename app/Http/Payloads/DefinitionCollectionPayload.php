<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Definition;
use JsonSerializable;

class DefinitionCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, Definition> $definitions
     */
    public function __construct(
        private readonly array $definitions
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
                fn (Definition $definition) => (new DefinitionPayload($definition))->toArray(),
                $this->definitions
            ),
            'meta' => [
                'total' => count($this->definitions),
            ],
        ];
    }
}
