<?php

declare(strict_types=1);

namespace App\Command\Results;

/**
 * Result DTO for parameterized SELECT query execution with pagination metadata.
 */
class SelectResultWithPagination extends SelectResult
{
    /**
     * @param array<string> $columns Column names
     * @param list<array<string,mixed>> $rows Associative rows
     * @param int $rowCount Total number of rows returned
     * @param bool $truncated Whether results were truncated due to row limit
     * @param int $limitApplied The row limit that was applied
     * @param bool $isAggregate Whether this query is an aggregate query
     * @param int|null $totalCount Total count of matching rows (null if not computed)
     * @param array{offset: int, row_limit: int} $parameters The parameters used for this query
     */
    public function __construct(
        array $columns,
        array $rows,
        int $rowCount,
        bool $truncated,
        int $limitApplied,
        public bool $isAggregate,
        public ?int $totalCount,
        public array $parameters
    ) {
        parent::__construct($columns, $rows, $rowCount, $truncated, $limitApplied);
    }

    /**
     * Create an empty result with pagination metadata for queries that return no data.
     */
    public static function empty(int $limitApplied = 25): self
    {
        return new self(
            columns: [],
            rows: [],
            rowCount: 0,
            truncated: false,
            limitApplied: $limitApplied,
            isAggregate: false,
            totalCount: null,
            parameters: [
                'offset' => 0,
                'row_limit' => $limitApplied,
            ]
        );
    }

    /**
     * Create a result from raw database rows with pagination metadata.
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
            limitApplied: $limitApplied,
            isAggregate: false,
            totalCount: null,
            parameters: [
                'offset' => 0,
                'row_limit' => $limitApplied,
            ]
        );
    }

    public function jsonSerialize(): array
    {
        return array_merge(parent::jsonSerialize(), [
            'limit_applied' => $this->limitApplied,
            'is_aggregate' => $this->isAggregate,
            'total_count' => $this->totalCount,
        ]);
    }
}
