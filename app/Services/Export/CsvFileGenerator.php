<?php

declare(strict_types=1);

namespace App\Services\Export;

use Exception;
use Illuminate\Support\Carbon;

/**
 * Service for generating CSV files from data
 */
class CsvFileGenerator
{
    /**
     * Create a CSV file from data and return the file path
     *
     * @param array $data Array with 'columns' and 'rows' keys
     * @param int $queryId Query ID for filename
     * @param int $sqlCallId SQL call ID for filename
     * @return string Path to the created CSV file
     */
    public function generateFile(array $data, int $queryId, int $sqlCallId): string
    {
        $filename = $this->buildFilename($queryId, $sqlCallId);
        $filePath = $this->buildFilePath($filename);

        $this->ensureTempDirectoryExists($filePath);
        $this->writeCsvFile($filePath, $data);

        return $filePath;
    }

    /**
     * Create a CSV file from batched data and return the file path.
     *
     * The consumer callable is called with a batch-writer callable:
     *   $consumer(callable(array{columns: array, rows: array}): void $writeBatch): void
     *
     * This keeps the database connection open while writing, avoiding use-after-close errors
     * that would occur if a Generator were returned across a connection boundary.
     *
     * @param callable(callable): void $consumer Receives a per-batch writer callable
     * @param int $queryId Query ID for filename
     * @param int $sqlCallId SQL call ID for filename
     * @return string Path to the created CSV file
     */
    public function generateFileFromBatches(callable $consumer, int $queryId, int $sqlCallId): string
    {
        $filename = $this->buildFilename($queryId, $sqlCallId);
        $filePath = $this->buildFilePath($filename);

        $this->ensureTempDirectoryExists($filePath);

        $handle = fopen($filePath, 'w');

        if ($handle === false) {
            throw new Exception('Failed to create temporary CSV file');
        }

        $headersWritten = false;

        $writeBatch = function (array $batch) use ($handle, &$headersWritten): void {
            if (! $headersWritten && ! empty($batch['columns'])) {
                $this->writeHeaders($handle, $batch['columns']);
                $headersWritten = true;
            }

            $this->writeRows($handle, $batch['rows'] ?? []);
        };

        try {
            $consumer($writeBatch);
            fflush($handle);
        } catch (Exception $e) {
            fclose($handle);
            $this->deleteFile($filePath);
            throw $e;
        }

        fclose($handle);

        return $filePath;
    }

    /**
     * Build filename for CSV export
     */
    private function buildFilename(int $queryId, int $sqlCallId): string
    {
        return sprintf('query_%d_call_%d_%s.csv', $queryId, $sqlCallId, Carbon::now()->format('Y_m_d_H_i_s'));
    }

    /**
     * Build full file path
     */
    private function buildFilePath(string $filename): string
    {
        return storage_path('app/temp/'.$filename);
    }

    private function ensureTempDirectoryExists(string $filePath): void
    {
        $tempDir = dirname($filePath);

        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
    }

    /**
     * Write data to CSV file
     */
    private function writeCsvFile(string $filePath, array $data): void
    {
        $handle = fopen($filePath, 'w');

        if ($handle === false) {
            throw new Exception('Failed to create temporary CSV file');
        }

        try {
            $this->writeHeaders($handle, $data['columns'] ?? []);
            $this->writeRows($handle, $data['rows'] ?? []);
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write CSV header row
     */
    private function writeHeaders($handle, array $columns): void
    {
        if (! empty($columns)) {
            fputcsv($handle, $columns);
        }
    }

    /**
     * Write CSV data rows
     */
    private function writeRows($handle, array $rows): void
    {
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
    }

    /**
     * Delete CSV file
     */
    public function deleteFile(string $filePath): void
    {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}
