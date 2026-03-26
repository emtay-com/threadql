<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Table;
use JsonSerializable;

class TableCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, Table> $tables
     */
    public function __construct(
        private readonly array $tables
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
            'data' => array_map(fn (Table $table) => (new TablePayload($table))->toArray(), $this->tables),
            'meta' => [
                'total' => count($this->tables),
            ],
        ];
    }
}
