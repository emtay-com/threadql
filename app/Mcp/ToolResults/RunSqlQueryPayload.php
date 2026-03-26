<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

use App\Domain\Sql\SqlToolResultKind;
use JsonSerializable;

class RunSqlQueryPayload implements JsonSerializable
{
    private bool $ok;

    private ?string $resultKind;

    private ?array $aggregate;

    private ?array $columns;

    private ?array $rows;

    private ?int $rowCount;

    private bool $truncated;

    private ?int $limitApplied;

    private ?int $tookMs;

    private ?string $error;

    private ?string $message;

    private function __construct()
    {
        $this->ok = true;
        $this->resultKind = null;
        $this->aggregate = null;
        $this->columns = null;
        $this->rows = null;
        $this->rowCount = null;
        $this->truncated = false;
        $this->limitApplied = null;
        $this->tookMs = null;
        $this->error = null;
        $this->message = null;
    }

    /**
     * Factory method for aggregate results (single cell/value).
     */
    public static function fromAggregate(string $label, $value, array $meta = []): self
    {
        $payload = new self();
        $payload->resultKind = SqlToolResultKind::Aggregate->value;
        $payload->aggregate = [
            'label' => $label,
            'value' => $value,
        ];
        $payload->rowCount = 1;
        $payload->truncated = false;
        $payload->limitApplied = $meta['limit_applied'] ?? null;
        $payload->tookMs = $meta['took_ms'] ?? null;

        return $payload;
    }

    /**
     * Factory method for row results.
     */
    public static function fromRows(array $rows, array $meta = []): self
    {
        $payload = new self();
        $payload->resultKind = 'rows';

        if (! empty($rows)) {
            $payload->columns = array_keys($rows[0]);
            $payload->rows = $rows;
            $payload->rowCount = count($rows);
        } else {
            $payload->columns = [];
            $payload->rows = [];
            $payload->rowCount = 0;
        }

        $payload->truncated = $meta['truncated'] ?? false;
        $payload->limitApplied = $meta['limit_applied'] ?? null;
        $payload->tookMs = $meta['took_ms'] ?? null;

        return $payload;
    }

    /**
     * Factory method for pending table results.
     */
    public static function pendingTable(
        string $message = 'Resultset will be posted in the Slack thread.',
        array $meta = []
    ): self {
        $payload = new self();
        $payload->resultKind = SqlToolResultKind::PendingTable->value;
        $payload->message = $message;
        $payload->tookMs = $meta['took_ms'] ?? null;

        return $payload;
    }

    /**
     * Factory method for no results.
     */
    public static function noResults(int $tookMs): self
    {
        $payload = new self();
        $payload->resultKind = SqlToolResultKind::NoResults->value;
        $payload->tookMs = $tookMs;

        return $payload;
    }

    /**
     * Factory method for empty results.
     */
    public static function emptyResult(array $meta = []): self
    {
        $payload = new self();
        $payload->resultKind = 'none';
        $payload->columns = [];
        $payload->rows = [];
        $payload->rowCount = 0;
        $payload->truncated = false;
        $payload->limitApplied = $meta['limit_applied'] ?? null;
        $payload->tookMs = $meta['took_ms'] ?? null;

        return $payload;
    }

    /**
     * Factory method for error results.
     */
    public static function error(string $errorMessage, array $meta = []): self
    {
        $payload = new self();
        $payload->ok = false;
        $payload->error = $errorMessage;
        $payload->tookMs = $meta['took_ms'] ?? null;

        return $payload;
    }

    /**
     * Create payload from raw PDO results.
     */
    public static function fromPdoResults(array $rows, array $meta = []): self
    {
        // If result is exactly one cell, treat as aggregate
        if (count($rows) === 1 && count($rows[0]) === 1) {
            $label = array_key_first($rows[0]);
            // If label is numeric (no alias), use 'value'
            if (is_numeric($label)) {
                $label = 'value';
            }
            $value = array_values($rows[0])[0];

            return self::fromAggregate($label, $value, $meta);
        }

        // Otherwise, treat as rows
        return self::fromRows($rows, $meta);
    }

    public function jsonSerialize(): array
    {
        $result = [
            'ok' => $this->ok,
            'took_ms' => $this->tookMs,
        ];

        if (! $this->ok) {
            $result['error'] = $this->error;

            return $result;
        }

        $result['result_kind'] = $this->resultKind;

        if ($this->resultKind === SqlToolResultKind::Aggregate->value) {
            $result['label'] = $this->aggregate['label'];
            $result['value'] = $this->aggregate['value'];
        } elseif ($this->resultKind === SqlToolResultKind::NoResults->value) {
            // No additional fields for no_results
        } elseif ($this->resultKind === 'rows') {
            $result['columns'] = $this->columns;
            $result['rows'] = $this->rows;
        } elseif ($this->resultKind === SqlToolResultKind::PendingTable->value) {
            $result['message'] = $this->message;
        }

        if ($this->resultKind === 'rows' || $this->resultKind === 'aggregate') {
            $result['row_count'] = $this->rowCount;
            $result['truncated'] = $this->truncated;

            if ($this->limitApplied !== null) {
                $result['limit_applied'] = $this->limitApplied;
            }
        }

        return $result;
    }
}
