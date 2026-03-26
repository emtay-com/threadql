<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\SlackUser;
use JsonSerializable;

class SlackUserPayload implements JsonSerializable
{
    public function __construct(
        private readonly SlackUser $slackUser
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->slackUser->id,
            'tenant_id' => $this->slackUser->tenant_id,
            'slack_user_id' => $this->slackUser->slack_user_id,
            'real_name' => $this->slackUser->real_name,
            'display_name' => $this->slackUser->display_name,
            'avatar_url' => $this->slackUser->avatar_url,
            'approved' => $this->slackUser->approved,
            'created_at' => $this->slackUser->created_at->toIso8601String(),
            'deleted_at' => $this->slackUser->deleted_at?->toIso8601String(),
        ];
    }
}
