<?php

declare(strict_types=1);

namespace App\Services\Sql;

/**
 * Service for detecting whether a SQL query is an aggregate query.
 *
 * An aggregate query is one that:
 * - Contains GROUP BY clause
 * - Has only aggregate functions (COUNT/SUM/AVG/MIN/MAX) in projection and no non-aggregated columns
 */
class AggregateDetector
{
    private const AGGREGATE_FUNCTIONS = [
        'COUNT',
        'SUM',
        'AVG',
        'MIN',
        'MAX',
        'STDDEV',
        'VARIANCE',
        'STDDEV_POP',
        'STDDEV_SAMP',
        'VAR_POP',
        'VAR_SAMP',
    ];

    /**
     * Determine if a SQL query is an aggregate query.
     *
     * @param string $sql The SQL query to analyze
     * @return bool True if the query is an aggregate query
     */
    public function isAggregateQuery(string $sql): bool
    {
        $normalizedSql = $this->normalizeSql($sql);

        // Check for GROUP BY clause
        if ($this->hasGroupBy($normalizedSql)) {
            return true;
        }

        // Check if projection contains only aggregate functions
        return $this->hasOnlyAggregateFunctions($normalizedSql);
    }

    /**
     * Check if the SQL contains a GROUP BY clause.
     */
    private function hasGroupBy(string $sql): bool
    {
        return preg_match('/\bGROUP\s+BY\b/i', $sql) === 1;
    }

    /**
     * Check if the projection contains only aggregate functions and no regular columns.
     */
    private function hasOnlyAggregateFunctions(string $sql): bool
    {
        $selectClause = $this->extractSelectClause($sql);
        if (! $selectClause) {
            return false;
        }

        $projections = $this->parseProjections($selectClause);

        // If no projections found, not an aggregate query
        if (empty($projections)) {
            return false;
        }

        // Check each projection
        foreach ($projections as $projection) {
            if (! $this->isAggregateProjection($projection)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract the SELECT clause from the SQL query.
     */
    private function extractSelectClause(string $sql): ?string
    {
        $pattern = '/\bSELECT\s+(.*?)\s+(?:FROM|WHERE|GROUP|ORDER|LIMIT|HAVING|$)/is';
        if (preg_match($pattern, $sql, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Parse individual projections from the SELECT clause.
     *
     * @return array<string>
     */
    private function parseProjections(string $selectClause): array
    {
        // Split on commas, but be careful with commas inside functions
        $projections = [];
        $current = '';
        $parenDepth = 0;

        $chars = str_split($selectClause);
        foreach ($chars as $char) {
            if ($char === '(') {
                $parenDepth++;
            } elseif ($char === ')') {
                $parenDepth--;
            } elseif ($char === ',' && $parenDepth === 0) {
                $projections[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (! empty(trim($current))) {
            $projections[] = trim($current);
        }

        return array_map('trim', array_filter($projections));
    }

    /**
     * Check if a single projection is an aggregate function.
     */
    private function isAggregateProjection(string $projection): bool
    {
        $normalized = strtoupper($projection);

        // Check for aggregate functions
        foreach (self::AGGREGATE_FUNCTIONS as $func) {
            if (str_contains($normalized, $func.'(')) {
                return true;
            }
        }

        // Check for common aliases (count(*), etc.)
        if (preg_match('/\b(?:COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $normalized)) {
            return true;
        }

        // If it looks like a column reference (contains letters/digits/underscores, possibly qualified)
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $projection)) {
            return false;
        }

        // Check for qualified column names (table.column)
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/', $projection)) {
            return false;
        }

        // Default to treating unknown projections as non-aggregate for safety
        return false;
    }

    /**
     * Normalize SQL by removing extra whitespace and comments.
     */
    private function normalizeSql(string $sql): string
    {
        // Remove single-line comments
        $sql = preg_replace('/--.*$/m', '', $sql);

        // Remove multi-line comments
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

        // Normalize whitespace
        $sql = preg_replace('/\s+/', ' ', $sql);

        return trim($sql);
    }
}
