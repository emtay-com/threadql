<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\User;
use JsonSerializable;

class UserPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly User $user
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
            'id' => $this->user->id,
            'username' => $this->user->username,
            'email' => $this->user->email,
            'level' => $this->user->level->value,
            'tenant_id' => $this->user->tenant_id,
            'tenant_name' => $this->user->tenant->name,
            'created_at' => $this->user->created_at->toIso8601String(),
            'updated_at' => $this->user->updated_at?->toIso8601String(),
        ];
    }
}
