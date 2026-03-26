<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Service for exporting query results to CSV with safety limits
 */
class ExportCsvService
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
        private readonly SlackMessenger $slackMessenger
    ) {
    }

    /**
     * Export full query results to CSV and deliver via Slack
     */
    public function exportFullQueryToCsv(Query $query, string $sql, array $params, int $rowCount): ExportResult
    {
        $tempFile = '';

        try {
            $datasource = $query->tenant->datasources->first();

            $result = $this->connector->withConnection($datasource, function ($connection) use (
                $sql,
                $params,
                &$tempFile,
                $rowCount,
                $query
            ) {
                $rows = $this->executeQuery($connection, $sql, $params);
                $fileSize = $this->createCsvFile($rows, $tempFile);
                $this->deliverCsvToSlack($query, $tempFile, $rowCount);

                return $fileSize;
            });

            return new ExportResult(success: true, bytes: $result, rowCount: $rowCount, filePath: $tempFile);

        } catch (Exception $e) {
            $this->handleExportFailure($e, $query->id, $sql, $tempFile);
            throw $e;
        }
    }

    /**
     * Execute the SQL query and return rows
     *
     * @param \Illuminate\Database\Connection $connection
     */
    private function executeQuery($connection, string $sql, array $params): array
    {
        // Strip LIMIT clause — CSV export fetches all rows
        $cleanSql = $this->stripLimitClause($sql);
        $cleanParams = $this->stripLimitParams($params);

        $statement = $connection->getPdo()
            ->prepare($cleanSql);
        $statement->execute($cleanParams);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($rows)) {
            throw new Exception('Query returned no rows');
        }

        return $rows;
    }

    /**
     * Create CSV file from rows data
     *
     * @return int File size in bytes
     */
    private function createCsvFile(array $rows, string &$tempFile): int
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_export_') ?: '';
        if ($tempFile === '') {
            throw new Exception('Failed to create temporary file');
        }
        $handle = fopen($tempFile, 'w');

        if ($handle === false) {
            throw new Exception('Failed to create temporary file');
        }

        try {
            $fileSize = $this->writeCsvData($handle, $rows);
        } finally {
            fclose($handle);
        }

        return $fileSize;
    }

    /**
     * Write CSV data to file handle
     *
     * @param resource $handle
     * @return int File size in bytes
     */
    private function writeCsvData($handle, array $rows): int
    {
        // Write CSV headers
        fputcsv($handle, array_keys($rows[0]));

        // Write CSV rows
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fflush($handle);

        return ftell($handle);
    }

    /**
     * Handle export failure by logging and cleaning up
     */
    private function handleExportFailure(Exception $e, int|string $queryId, string $sql, string $tempFile): void
    {
        Log::error('CSV export failed', [
            'query_id' => $queryId,
            'error' => $e->getMessage(),
            'sql' => $sql,
        ]);

        // Clean up temp file if it exists
        if ($tempFile !== '' && file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    /**
     * Deliver CSV file to Slack thread
     */
    private function deliverCsvToSlack(Query $query, string $filePath, int $rowCount): void
    {
        $fileSize = filesize($filePath);

        // Prepare file upload configuration
        $files = [
            [
                'path' => $filePath,
                'content' => file_get_contents($filePath),
                'snippet_type' => 'csv',
                'title' => 'Query Results Export',
            ],
        ];

        $initialComment = sprintf('CSV export with %d rows (%d bytes)', $rowCount, $fileSize);

        // Upload file to Slack thread
        $success = $this->slackMessenger->uploadFile(
            $query->tenant,
            $files,
            $query->thread->channel_id,
            $initialComment,
            $query->thread->thread_ts
        );

        if (! $success) {
            throw new Exception('Failed to upload CSV to Slack');
        }
    }

    /**
     * Get row count for a query using COUNT(*)
     */
    public function getRowCount(Query $query, string $sql, array $params): int
    {
        try {
            $datasource = $query->tenant->datasources->first();
            if (! $datasource) {
                throw new Exception('No datasource found for tenant');
            }

            return $this->connector->withConnection($datasource, function ($connection) use ($sql, $params) {
                // Try to convert SELECT to COUNT(*) query
                $countSql = $this->convertToCountQuery($sql);
                $cleanParams = $this->stripLimitParams($params);

                $statement = $connection->getPdo()
                    ->prepare($countSql);
                $statement->execute($cleanParams);
                $result = $statement->fetch(\PDO::FETCH_ASSOC);

                return (int) ($result['count'] ?? 0);
            });

        } catch (Exception $e) {
            Log::warning('Failed to get row count with COUNT query, falling back to limited SELECT', [
                'query_id' => $query->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback: execute original query with LIMIT 1 to check if it returns rows
            return $this->getRowCountFallback($query, $sql, $params);
        }
    }

    /**
     * Convert SELECT query to COUNT(*) query
     */
    private function convertToCountQuery(string $sql): string
    {
        // Simple conversion - replace everything between SELECT and FROM with COUNT(*)
        // This is a basic implementation and may not work for complex queries
        $upperSql = strtoupper($sql);

        $selectPos = strpos($upperSql, 'SELECT');
        $fromPos = strpos($upperSql, ' FROM', $selectPos);

        if ($selectPos === false || $fromPos === false) {
            throw new Exception('Unable to parse SQL query for COUNT conversion');
        }

        $countSql = 'SELECT COUNT(*) as count'.substr($sql, $fromPos);

        // Strip LIMIT/OFFSET clauses — not needed for COUNT and may contain unbound placeholders
        return $this->stripLimitClause($countSql);
    }

    /**
     * Fallback method to get approximate row count
     */
    private function getRowCountFallback(Query $query, string $sql, array $params): int
    {
        try {
            $datasource = $query->tenant->datasources->first();
            if (! $datasource) {
                return 0; // No datasource available
            }

            return $this->connector->withConnection($datasource, function ($connection) use ($sql, $params) {
                $maxRows = (int) config('export.max_rows_async_export', 10000);

                // Strip existing LIMIT clause before adding our own
                $baseSql = $this->stripLimitClause($sql);
                $cleanParams = $this->stripLimitParams($params);
                $limitedSql = $baseSql.' LIMIT '.($maxRows + 1);

                $statement = $connection->getPdo()
                    ->prepare($limitedSql);
                $statement->execute($cleanParams);
                $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

                return count($rows);
            });

        } catch (Exception $e) {
            Log::error('Fallback row count also failed', [
                'query_id' => $query->id,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Strip LIMIT/OFFSET clause from SQL query
     */
    private function stripLimitClause(string $sql): string
    {
        // Remove LIMIT clause (with optional OFFSET) including named/positional placeholders
        // Handles: LIMIT N, LIMIT :param, LIMIT N OFFSET N, LIMIT :param, :param
        return (string) preg_replace('/\s+LIMIT\s+\S+(?:\s*,\s*\S+)?(?:\s+OFFSET\s+\S+)?\s*$/i', '', $sql);
    }

    /**
     * Remove limit-related named parameters that won't be in the cleaned SQL
     */
    private function stripLimitParams(array $params): array
    {
        $limitKeys = ['offset', 'row_limit', 'limit', ':offset', ':row_limit', ':limit'];

        return array_diff_key($params, array_flip($limitKeys));
    }
}
