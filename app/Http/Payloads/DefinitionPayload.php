<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Definition;
use JsonSerializable;

class DefinitionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly Definition $definition
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
            'id' => $this->definition->id,
            'tenant_id' => $this->definition->tenant_id,
            'subject' => $this->definition->subject,
            'definition' => $this->definition->definition,
            'priority' => $this->definition->priority,
            'created_at' => $this->definition->created_at->toIso8601String(),
        ];
    }
}
