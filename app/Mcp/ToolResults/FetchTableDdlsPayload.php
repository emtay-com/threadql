<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

use JsonSerializable;

/**
 * Payload for fetch_table_ddls MCP tool results
 */
class FetchTableDdlsPayload implements JsonSerializable
{
    private bool $ok;

    private ?int $tenantId;

    private ?int $queryId;

    private ?array $requested;

    private ?array $found;

    private ?array $missing;

    private ?array $skipped;

    private ?bool $truncated;

    private ?string $error;

    private function __construct()
    {
        $this->ok = true;
        $this->tenantId = null;
        $this->queryId = null;
        $this->requested = null;
        $this->found = null;
        $this->missing = null;
        $this->skipped = null;
        $this->truncated = false;
        $this->error = null;
    }

    /**
     * Factory method for successful DDL fetch results
     */
    public static function success(int $tenantId, int $queryId, array $requested, array $result): self
    {
        $payload = new self();
        $payload->tenantId = $tenantId;
        $payload->queryId = $queryId;
        $payload->requested = $requested;
        $payload->found = $result['found'];
        $payload->missing = $result['missing'];
        $payload->skipped = $result['skipped'];
        $payload->truncated = $result['truncated'];

        return $payload;
    }

    /**
     * Factory method for error results
     */
    public static function error(string $errorMessage): self
    {
        $payload = new self();
        $payload->ok = false;
        $payload->error = $errorMessage;

        return $payload;
    }

    public function jsonSerialize(): array
    {
        $result = [
            'ok' => $this->ok,
        ];

        if (! $this->ok) {
            $result['error'] = $this->error;

            return $result;
        }

        $result['tenant_id'] = $this->tenantId;
        $result['query_id'] = $this->queryId;
        $result['requested'] = $this->requested;
        $result['found'] = $this->found;
        $result['missing'] = $this->missing;
        $result['skipped'] = $this->skipped;
        $result['truncated'] = $this->truncated;

        return $result;
    }
}
