<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Table;
use JsonSerializable;

class TablePayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly Table $table
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
            'id' => $this->table->id,
            'tenant_id' => $this->table->tenant_id,
            'name' => $this->table->name,
            'priority' => $this->table->priority,
            'row_count' => $this->table->row_count,
            'created_at' => $this->table->created_at->toIso8601String(),
            'deleted_at' => $this->table->deleted_at?->toIso8601String(),
        ];
    }
}
