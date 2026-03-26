<?php

declare(strict_types=1);

namespace App\Http\Payloads;

use App\Models\Datasource;
use JsonSerializable;

class DataSourcePayload implements JsonSerializable
{
    /**
     * Create a new payload instance.
     */
    public function __construct(
        private readonly Datasource $datasource
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
            'id' => $this->datasource->id,
            'tenant_id' => $this->datasource->tenant_id,
            'label' => $this->datasource->label,
            'has_dsn' => ! empty($this->datasource->dsn),
            'allowed_schemas' => $this->datasource->allowed_schemas_json,
            'default_limit' => $this->datasource->default_limit,
            'query_timeout_seconds' => $this->datasource->query_timeout_seconds,
            'timezone' => $this->datasource->timezone,
            'use_ssh' => $this->datasource->use_ssh,
            'ssh_host' => $this->datasource->ssh_host,
            'ssh_port' => $this->datasource->ssh_port,
            'ssh_username' => $this->datasource->ssh_username,
            'has_ssh_password' => ! empty($this->datasource->ssh_password),
            'has_ssh_private_key' => ! empty($this->datasource->ssh_private_key),
            'ssh_public_key' => $this->datasource->ssh_public_key,
            'created_at' => $this->datasource->created_at->toIso8601String(),
            'updated_at' => $this->datasource->updated_at?->toIso8601String(),
        ];
    }
}
