<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\SlackUser;
use JsonSerializable;

class SlackUserCollectionPayload implements JsonSerializable
{
    /**
     * @param  array<int, SlackUser>  $slackUsers
     */
    public function __construct(
        private readonly array $slackUsers
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => array_map(
                fn (SlackUser $slackUser) => (new SlackUserPayload($slackUser))->toArray(),
                $this->slackUsers
            ),
            'meta' => [
                'total' => count($this->slackUsers),
            ],
        ];
    }
}
