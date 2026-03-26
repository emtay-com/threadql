<?php

declare(strict_types=1);

namespace App\Services\Sql;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Service for estimating total count of rows for a SQL query by executing a COUNT(*) version.
 */
class TotalCountEstimator
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector
    ) {
    }

    /**
     * Estimate the total count of rows for a query by executing a COUNT(*) version.
     *
     * @param  string  $originalSql  The original SELECT SQL
     * @param  array<string, mixed>  $originalParameters  The bound parameters from the original query
     * @param  Datasource  $datasource  The datasource to execute against
     * @return int|null The total count, or null if estimation fails
     */
    public function estimateTotalCount(string $originalSql, array $originalParameters, Datasource $datasource): ?int
    {
        try {
            $countSql = $this->buildCountQuery($originalSql);
            $countParameters = $this->filterParametersForCount($originalParameters);
            $timeoutStrategy = $this->connector->getTimeoutStrategy($datasource);

            $count = $this->connector->withConnection($datasource, function (Connection $connection) use (
                $countSql,
                $countParameters,
                $timeoutStrategy
            ) {
                // Set query timeout (shorter for count queries)
                $timeout = config('llm.sql_execution.query_timeout_seconds', 8);
                $timeoutStrategy->setTimeout($connection, $timeout);

                $stmt = $connection->getPdo()
                    ->prepare($countSql);

                // Bind parameters
                foreach ($countParameters as $param => $value) {
                    $pdoType = $this->determinePdoType($value);
                    $stmt->bindValue($param, $value, $pdoType);
                }

                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);

                return $result ? (int) $result['total_count'] : null;
            });

            return $count;

        } catch (Exception $e) {
            Log::error('Error estimating total count: '.$e->getMessage(), [
                $this->buildCountQuery($originalSql),
                $e,
            ]);

            // Count estimation failure should be graceful - don't log in unit tests
            // In production, this could be logged if needed
            return null;
        }
    }

    /**
     * Build a COUNT(*) query from the original SELECT query.
     *
     * @param  string  $originalSql  The original SELECT SQL
     * @return string The COUNT(*) version of the query
     */
    public function buildCountQuery(string $originalSql): string
    {
        $normalized = $this->normalizeSql($originalSql);

        // Queries with GROUP BY, HAVING, DISTINCT, or UNION need subquery wrapping
        // to correctly count the number of result rows
        if ($this->needsSubqueryWrapping($normalized)) {
            $stripped = $this->removeOrderingAndLimits($normalized);

            return "SELECT COUNT(*) AS total_count FROM ($stripped) t";
        }

        // Simple SELECT: extract FROM clause and wrap with COUNT(*)
        $fromPattern = '/\bFROM\s+(.+?)(?:\s+(?:ORDER\s+BY|LIMIT|OFFSET)\b.*)?$/is';
        if (! preg_match($fromPattern, $normalized, $matches)) {
            // Fallback: if we can't parse it properly, wrap the whole query
            return "SELECT COUNT(*) AS total_count FROM ($originalSql) t";
        }

        $fromClause = $matches[1];

        // Remove ORDER BY, LIMIT, OFFSET from the FROM clause
        $fromClause = $this->removeOrderingAndLimits($fromClause);

        return "SELECT COUNT(*) AS total_count FROM $fromClause";
    }

    /**
     * Filter parameters to exclude row_limit and offset for count queries.
     *
     * @param  array<string, mixed>  $originalParameters
     * @return array<string, mixed>
     */
    public function filterParametersForCount(array $originalParameters): array
    {
        $filtered = [];
        $excludeParams = ['row_limit', 'offset'];

        foreach ($originalParameters as $param => $value) {
            if (! in_array($param, $excludeParams, true)) {
                $filtered[$param] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Check if the query requires subquery wrapping for accurate COUNT.
     *
     * Queries with GROUP BY, HAVING, DISTINCT, or UNION change the result set
     * cardinality, so a simple COUNT(*) on the FROM clause would be incorrect.
     */
    private function needsSubqueryWrapping(string $sql): bool
    {
        // CTEs (WITH ... AS) make simple FROM extraction unreliable
        if (preg_match('/^\s*WITH\b/i', $sql)) {
            return true;
        }

        // Check for DISTINCT in the SELECT clause
        if (preg_match('/\bSELECT\s+DISTINCT\b/i', $sql)) {
            return true;
        }

        // Check for GROUP BY clause
        if (preg_match('/\bGROUP\s+BY\b/i', $sql)) {
            return true;
        }

        // Check for HAVING clause
        if (preg_match('/\bHAVING\b/i', $sql)) {
            return true;
        }

        // Check for set operations (UNION, INTERSECT, EXCEPT)
        if (preg_match('/\b(?:UNION|INTERSECT|EXCEPT)\b/i', $sql)) {
            return true;
        }

        return false;
    }

    /**
     * Remove ORDER BY, LIMIT, and OFFSET clauses from SQL.
     */
    private function removeOrderingAndLimits(string $sql): string
    {
        // Remove ORDER BY clause and everything after it
        $sql = preg_replace('/\s+ORDER\s+BY\s+.*$/i', '', $sql);

        // Remove LIMIT clause and everything after it
        $sql = preg_replace('/\s+LIMIT\s+.*$/i', '', $sql);

        // Remove OFFSET clause and everything after it
        $sql = preg_replace('/\s+OFFSET\s+.*$/i', '', $sql);

        return trim($sql);
    }

    /**
     * Normalize SQL by removing extra whitespace.
     */
    private function normalizeSql(string $sql): string
    {
        // Remove extra whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
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
