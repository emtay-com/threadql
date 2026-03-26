<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\User;
use JsonSerializable;

class UserCollectionPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     *
     * @param array<int, User> $users
     */
    public function __construct(
        private readonly array $users
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
            'data' => array_map(fn (User $user) => (new UserPayload($user))->toArray(), $this->users),
            'meta' => [
                'total' => count($this->users),
            ],
        ];
    }
}
