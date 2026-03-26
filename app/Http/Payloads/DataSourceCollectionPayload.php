<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Datasource;
use JsonSerializable;

class DataSourceCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, Datasource> $datasources
     */
    public function __construct(
        private readonly array $datasources,
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
                fn (Datasource $datasource) => (new DataSourcePayload($datasource))->toArray(),
                $this->datasources
            ),
            'meta' => [
                'total' => count($this->datasources),
            ],
        ];
    }
}
