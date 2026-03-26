<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

/**
 * Result payload for export_csv MCP tool
 */
class ExportCsvPayload implements \JsonSerializable
{
    private function __construct(
        private readonly bool $ok,
        private readonly string $status,
        private readonly ?string $message,
        private readonly ?int $queryId,
        private readonly ?int $sqlCallId,
        private readonly ?int $rowLimit,
        private readonly array $meta = []
    ) {
    }

    /**
     * Create a pending export payload
     */
    public static function pending(int $queryId, int $sqlCallId, ?int $rowLimit, array $meta = []): self
    {
        return new self(
            ok: true,
            status: 'pending',
            message: 'CSV export requested; delivering shortly.',
            queryId: $queryId,
            sqlCallId: $sqlCallId,
            rowLimit: $rowLimit,
            meta: $meta
        );
    }

    /**
     * Create an error payload
     */
    public static function error(string $message, array $meta = []): self
    {
        return new self(
            ok: false,
            status: 'error',
            message: $message,
            queryId: null,
            sqlCallId: null,
            rowLimit: null,
            meta: $meta
        );
    }

    /**
     * Get the ok status
     */
    public function getOk(): bool
    {
        return $this->ok;
    }

    /**
     * Get the status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Get the message
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * Get the query ID
     */
    public function getQueryId(): ?int
    {
        return $this->queryId;
    }

    /**
     * Get the SQL call ID
     */
    public function getSqlCallId(): ?int
    {
        return $this->sqlCallId;
    }

    /**
     * Get the row limit
     */
    public function getRowLimit(): ?int
    {
        return $this->rowLimit;
    }

    /**
     * Get the meta data
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * Convert to JSON serializable array
     */
    public function jsonSerialize(): array
    {
        $result = [
            'ok' => $this->ok,
            'status' => $this->status,
        ];

        if ($this->message !== null) {
            $result['message'] = $this->message;
        }

        if ($this->queryId !== null) {
            $result['query_id'] = $this->queryId;
        }

        if ($this->sqlCallId !== null) {
            $result['sql_call_id'] = $this->sqlCallId;
        }

        if ($this->rowLimit !== null) {
            $result['row_limit'] = $this->rowLimit;
        }

        if (! empty($this->meta)) {
            $result['meta'] = $this->meta;
        }

        return $result;
    }
}
