<?php

declare(strict_types=1);

namespace App\Command\Results;

use JsonSerializable;

class SelectResult implements JsonSerializable
{
    /**
     * @param array<string> $columns Column names
     * @param list<array<string,mixed>> $rows Associative rows
     * @param int $rowCount Total number of rows returned
     * @param bool $truncated Whether results were truncated due to row limit
     * @param int $limitApplied The row limit that was applied
     */
    public function __construct(
        public array $columns,
        public array $rows,
        public int $rowCount,
        public bool $truncated,
        public int $limitApplied
    ) {
    }

    /**
     * Create an empty result for queries that return no data.
     */
    public static function empty(int $limitApplied = 25): self
    {
        return new self(columns: [], rows: [], rowCount: 0, truncated: false, limitApplied: $limitApplied);
    }

    /**
     * Create a result from raw database rows.
     *
     * @param list<array<string,mixed>> $rows Raw database rows
     * @param int $limitApplied The limit that was applied
     */
    public static function fromRows(array $rows, int $limitApplied): self
    {
        $columns = empty($rows) ? [] : array_keys($rows[0]);
        $rowCount = count($rows);
        $truncated = $rowCount >= $limitApplied;

        return new self(
            columns: $columns,
            rows: $rows,
            rowCount: $rowCount,
            truncated: $truncated,
            limitApplied: $limitApplied
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'columns' => $this->columns,
            'rows' => $this->rows,
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
            'limit_applied' => $this->limitApplied,
        ];
    }
}
