<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use Generator;
use Illuminate\Database\Connection;
use PDO;

/**
 * Service for executing SQL queries and exporting data for CSV generation
 */
class CsvDataExporter
{
    private const BATCH_SIZE = 10000;

    public function __construct(
        private readonly DynamicDatabaseConnector $connector
    ) {
    }

    /**
     * Execute SQL query and return data for CSV export
     *
     * @return array{columns: array, rows: array}
     */
    public function exportData(Datasource $datasource, string $sql, array $parameters, ?int $rowLimit): array
    {
        $timeoutStrategy = $this->connector->getTimeoutStrategy($datasource);

        return $this->connector->withConnection($datasource, function (Connection $connection) use (
            $sql,
            $parameters,
            $rowLimit,
            $timeoutStrategy
        ) {
            $timeout = config('llm.sql_execution.query_timeout_seconds', 8);
            $timeoutStrategy->setTimeout($connection, $timeout);

            $stmt = $this->prepareStatement($connection, $sql);
            $this->bindParameters($stmt, $parameters, $rowLimit);

            $stmt->execute();

            return $this->fetchResults($stmt);
        });
    }

    /**
     * Execute SQL query and process results in batches for memory-efficient large exports.
     * The provided consumer callable is invoked with each batch while the database connection
     * remains open, avoiding the use-after-close problem that a returned Generator would cause.
     *
     * @param callable(array{columns: array, rows: array}): void $consumer
     */
    public function exportDataBatched(
        Datasource $datasource,
        string $sql,
        array $parameters,
        ?int $rowLimit,
        callable $consumer,
    ): void {
        $timeoutStrategy = $this->connector->getTimeoutStrategy($datasource);

        $this->connector->withConnection($datasource, function (Connection $connection) use (
            $sql,
            $parameters,
            $rowLimit,
            $timeoutStrategy,
            $consumer,
        ) {
            $timeout = config('llm.sql_execution.query_timeout_seconds', 8);
            $timeoutStrategy->setTimeout($connection, $timeout);

            $stmt = $this->prepareStatement($connection, $sql);
            $this->bindParameters($stmt, $parameters, $rowLimit);

            $stmt->execute();

            foreach ($this->fetchResultsBatched($stmt) as $batch) {
                $consumer($batch);
            }
        });
    }

    /**
     * Prepare SQL statement, stripping LIMIT/OFFSET clauses for full CSV export
     */
    private function prepareStatement(Connection $connection, string $sql): \PDOStatement
    {
        $cleanSql = $this->stripLimitClause($sql);

        return $connection->getPdo()
            ->prepare($cleanSql);
    }

    /**
     * Strip LIMIT/OFFSET clause from SQL query
     */
    private function stripLimitClause(string $sql): string
    {
        return (string) preg_replace('/\s+LIMIT\s+\S+(?:\s*,\s*\S+)?(?:\s+OFFSET\s+\S+)?\s*$/i', '', $sql);
    }

    /**
     * Bind parameters to prepared statement.
     *
     * Strips limit-related placeholders (:offset, :row_limit) from the SQL
     * since the CSV export needs all matching rows. If the SQL contains those
     * placeholders they are removed by stripLimitClause() in the prepare step.
     */
    private function bindParameters(\PDOStatement $stmt, array $parameters, ?int $rowLimit): void
    {
        // Strip limit-related params — the SQL LIMIT clause is removed for CSV exports
        $limitKeys = ['offset', 'row_limit', 'limit', ':offset', ':row_limit', ':limit'];
        $cleanParams = array_diff_key($parameters, array_flip($limitKeys));

        // Bind all provided parameters
        foreach ($cleanParams as $param => $value) {
            $pdoType = $this->determinePdoType($value);
            $stmt->bindValue($param, $value, $pdoType);
        }
    }

    /**
     * Fetch all results from statement
     *
     * @return array{columns: array, rows: array}
     */
    private function fetchResults(\PDOStatement $stmt): array
    {
        $rows = [];
        $columns = [];

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (empty($columns)) {
                $columns = array_keys($row);
            }
            $rows[] = $row;
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Fetch results from statement in batches using a generator
     *
     * @return Generator<int, array{columns: array, rows: array}>
     */
    private function fetchResultsBatched(\PDOStatement $stmt): Generator
    {
        $columns = [];
        $batch = [];
        $batchCount = 0;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (empty($columns)) {
                $columns = array_keys($row);
            }

            $batch[] = $row;
            $batchCount++;

            if ($batchCount >= self::BATCH_SIZE) {
                yield [
                    'columns' => $columns,
                    'rows' => $batch,
                ];
                $batch = [];
                $batchCount = 0;
            }
        }

        // Yield remaining rows
        if (! empty($batch)) {
            yield [
                'columns' => $columns,
                'rows' => $batch,
            ];
        }
    }

    /**
     * Determine the appropriate PDO parameter type for a value
     */
    private function determinePdoType(mixed $value): int
    {
        if (is_null($value)) {
            return PDO::PARAM_NULL;
        }

        if (is_bool($value)) {
            return PDO::PARAM_BOOL;
        }

        if (is_int($value)) {
            return PDO::PARAM_INT;
        }

        return PDO::PARAM_STR;
    }
}
