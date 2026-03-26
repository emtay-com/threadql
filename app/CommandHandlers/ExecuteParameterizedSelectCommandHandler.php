<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\ExecuteParameterizedSelectCommand;
use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Command\Results\SelectResultWithPagination;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Query;
use App\Services\Sql\AggregateDetector;
use App\Services\Sql\TotalCountEstimator;
use Exception;
use Illuminate\Database\Connection;
use PDO;

/**
 * Handler for executing parameterized SELECT queries with read-only safeguards.
 */
class ExecuteParameterizedSelectCommandHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
        private readonly AggregateDetector $aggregateDetector,
        private readonly TotalCountEstimator $totalCountEstimator
    ) {
    }

    /**
     * Execute the parameterized SELECT command.
     */
    public function __invoke(ExecuteParameterizedSelectCommand $command): ExecuteParameterizedSelectCommandResponse
    {
        try {
            // Resolve Query → Thread → Tenant → primary datasource
            $query = Query::with(['thread', 'tenant.datasources'])->find($command->queryId);

            if (! $query) {
                return ExecuteParameterizedSelectCommandResponse::error('Query not found');
            }

            if ($query->tenant->datasources->isEmpty()) {
                return ExecuteParameterizedSelectCommandResponse::error('Query is missing required relationships');
            }

            // Validate SQL is a SELECT statement
            if (! $this->isSelectStatement($command->sql)) {
                return ExecuteParameterizedSelectCommandResponse::error('Only SELECT statements are allowed');
            }

            $datasource = $query->tenant->datasources->first();
            $timeoutStrategy = $this->connector->getTimeoutStrategy($datasource);

            // Execute the query with read-only connection
            $result = $this->connector->withConnection($datasource, function (Connection $connection) use (
                $command,
                $timeoutStrategy
            ) {
                // Set query timeout
                $timeout = config('llm.sql_execution.query_timeout_seconds', 8);
                $timeoutStrategy->setTimeout($connection, $timeout);

                // Prepare and execute the statement
                $stmt = $connection->getPdo()
                    ->prepare($command->sql);

                // Apply row limit if specified
                $effectiveLimit = $this->getEffectiveLimit($command);
                $parameters = $command->parameters;

                if (! array_key_exists(':row_limit', $parameters)) {
                    $stmt->bindValue(':row_limit', $effectiveLimit, PDO::PARAM_INT);
                }

                if (! array_key_exists(':offset', $parameters) && str_contains($command->sql, ':offset')) {
                    $stmt->bindValue(':offset', 0, PDO::PARAM_INT);
                }

                // Bind all provided parameters
                foreach ($parameters as $param => $value) {
                    $pdoType = $this->determinePdoType($value);
                    $stmt->bindValue($param, $value, $pdoType);
                }

                $stmt->execute();

                // Fetch results
                $rows = [];
                $rowCount = 0;

                while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                    if ($rowCount >= $effectiveLimit) {
                        break; // Stop fetching when limit is reached
                    }
                    $rows[] = $row;
                    $rowCount++;
                }

                // Get column names
                $columns = [];
                if ($rowCount > 0) {
                    $columns = array_keys($rows[0]);
                }

                return new SelectResult(
                    columns: $columns,
                    rows: $rows,
                    rowCount: $rowCount,
                    truncated: $rowCount >= $effectiveLimit,
                    limitApplied: $effectiveLimit
                );
            });

            // Detect if this is an aggregate query
            $isAggregate = $this->aggregateDetector->isAggregateQuery($command->sql);

            // Estimate total count for non-aggregate queries
            $totalCount = null;
            if (! $isAggregate) {
                $totalCount = $this->totalCountEstimator->estimateTotalCount(
                    $command->sql,
                    $command->parameters,
                    $datasource
                );
            }

            // Augment the result with pagination metadata
            $augmentedResult = new SelectResultWithPagination(
                columns: $result->columns,
                rows: $result->rows,
                rowCount: $result->rowCount,
                truncated: $result->truncated,
                limitApplied: $result->limitApplied,
                isAggregate: $isAggregate,
                totalCount: $totalCount,
                parameters: [
                    'offset' => $command->parameters[':offset'] ?? 0,
                    'row_limit' => $this->getEffectiveLimit($command),
                ]
            );

            return ExecuteParameterizedSelectCommandResponse::success($augmentedResult);
        } catch (Exception $e) {
            return ExecuteParameterizedSelectCommandResponse::error('Database error: '.$e->getMessage());
        }
    }

    /**
     * Check if the SQL is a SELECT statement.
     */
    private function isSelectStatement(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));

        return str_starts_with($normalized, 'SELECT');
    }

    private function getEffectiveLimit(ExecuteParameterizedSelectCommand $command): int
    {
        return $command->rowLimit ?? config('llm.default_row_limit', 25);
    }

    /**
     * Determine the appropriate PDO parameter type for a value.
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
