<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Tenant;
use JsonSerializable;

class TenantPayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly Tenant $tenant
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

    public function toArray(): array
    {
        return [
            'id' => $this->tenant->id,
            'name' => $this->tenant->name,
            'bot_name' => $this->tenant->bot_name,
            'uuid' => $this->tenant->uuid,
            'timezone' => $this->tenant->timezone,
            'slack_app_id' => $this->tenant->slack_app_id,
            'slack_client_id' => $this->tenant->slack_client_id,
            'has_slack_bot_token' => ! empty($this->tenant->slack_bot_token),
            'has_slack_signing_secret' => ! empty($this->tenant->slack_signing_secret),
            'has_slack_verification_token' => ! empty($this->tenant->slack_verification_token),
            'created_at' => $this->tenant->created_at->toIso8601String(),
            'updated_at' => $this->tenant->updated_at?->toIso8601String(),
        ];
    }
}
