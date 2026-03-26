<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueryStatus;
use App\Exceptions\EntityNotFoundException;
use App\Http\Controllers\Tenant\DownloadCsvController;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Mcp\ToolResults\CsvExportFailedResult;
use App\Models\Query;
use App\Models\ToolCall;
use App\Services\Export\CsvDataExporter;
use App\Services\Export\CsvFileGenerator;
use App\Services\Export\SlackCsvUploader;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Job to export SQL query results to CSV and deliver via Slack
 */
class ExportCsvAndDeliverJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use JobParamAssigner;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 900; // 15 minutes for small exports

    #[Assignable]
    private CsvDataExporter $dataExporter;

    #[Assignable]
    private CsvFileGenerator $fileGenerator;

    #[Assignable]
    private SlackCsvUploader $slackUploader;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new FailOnUnrecoverableException];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $queryId,
        private readonly int $sqlCallId,
        private readonly string $sql,
        private readonly array $parameters,
        private readonly ?int $rowLimit,
        private readonly bool $asyncLargeExport = false,
        private readonly ?int $toolCallId = null,
    ) {
    }

    /**
     * Get the job's timeout based on export type.
     */
    public function retryUntil(): \DateTime
    {
        $timeout = $this->asyncLargeExport ? 1800 : 900;

        return now()->addSeconds($timeout);
    }

    /**
     * Execute the job.
     */
    public function handle(
        CsvDataExporter $dataExporter,
        CsvFileGenerator $fileGenerator,
        SlackCsvUploader $slackUploader
    ): void {
        $this->assignParams(func_get_args());

        $query = $this->loadQueryWithThread();
        $this->validateThread($query->thread);
        $this->logJobStart();

        if ($this->asyncLargeExport) {
            $this->handleLargeExport($query);
        } else {
            $this->handleSmallExport($query);
        }
    }

    /**
     * Handle small export: export data, generate CSV, upload to Slack
     */
    private function handleSmallExport(Query $query): void
    {
        try {
            $csvData = $this->exportCsvData($query);

            if (empty($csvData['rows'])) {
                $this->handleEmptyResults($query);

                return;
            }

            $filePath = $this->generateCsvFile($csvData);

            try {
                $this->uploadToSlack($query, $filePath);
                $query->update([
                    'status' => QueryStatus::DONE->value,
                    'sql_text' => $this->sql,
                    'parameters' => $this->parameters,
                    'result_meta_json' => [
                        'is_aggregate' => false,
                        'row_count' => count($csvData['rows']),
                        'export' => 'csv_sync',
                    ],
                ]);
                $this->logJobCompletion(count($csvData['rows']), filesize($filePath) ?: 0);
            } finally {
                $this->fileGenerator->deleteFile($filePath);
            }
        } catch (Throwable $e) {
            Log::error('CSV export job failed', [
                'query_id' => $this->queryId,
                'sql_call_id' => $this->sqlCallId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle large async export: batched fetch, write to disk, send download link
     */
    private function handleLargeExport(Query $query): void
    {
        $filePath = null;

        try {
            $datasource = $query->tenant->datasources->first();

            $filePath = $this->fileGenerator->generateFileFromBatches(
                function (callable $writeBatch) use ($datasource): void {
                    $this->dataExporter->exportDataBatched(
                        $datasource,
                        $this->sql,
                        $this->parameters,
                        $this->rowLimit,
                        $writeBatch,
                    );
                },
                $this->queryId,
                $this->sqlCallId,
            );
            $rowCount = $this->countCsvRows($filePath);

            if ($rowCount === 0) {
                $this->handleEmptyResults($query);
                $this->updateToolCallRecord(true, [
                    'status' => 'empty',
                    'message' => 'Query returned no results',
                ]);

                return;
            }

            $diskPath = $this->storeToDisk($query, $filePath);
            $downloadUrl = $this->generateDownloadUrl($query, $diskPath);
            $expiresAt = now()
                ->addMinutes(config('export.link_expiration_minutes', 60));

            $this->updateToolCallRecord(true, [
                'status' => 'completed',
                'row_count' => $rowCount,
                'disk_path' => $diskPath,
                'download_url' => $downloadUrl,
            ]);

            SendCsvExportLinkJob::dispatch(
                $this->queryId,
                $downloadUrl,
                $rowCount,
                $expiresAt->toIso8601String(),
            );

            $query->update([
                'status' => QueryStatus::DONE->value,
                'sql_text' => $this->sql,
                'parameters' => $this->parameters,
                'result_meta_json' => [
                    'is_aggregate' => false,
                    'row_count' => $rowCount,
                    'export' => 'csv_async',
                ],
            ]);
            $this->logJobCompletion($rowCount, filesize($filePath) ?: 0);

        } catch (Throwable $e) {
            Log::error('Large CSV export job failed', [
                'query_id' => $this->queryId,
                'sql_call_id' => $this->sqlCallId,
                'error' => $e->getMessage(),
            ]);

            $this->updateToolCallRecord(false, CsvExportFailedResult::unexpected($e->getMessage()));

            throw $e;
        } finally {
            if ($filePath) {
                $this->fileGenerator->deleteFile($filePath);
            }
        }
    }

    /**
     * Store the CSV file to the configured export disk
     */
    private function storeToDisk(Query $query, string $localFilePath): string
    {
        $disk = Storage::disk(config('export.disk', 'exports'));
        $tenantUuid = $query->tenant->uuid;
        $filename = sprintf(
            'query_%s.csv',
            substr(hash('sha256', $query->thread->message_ts ?? (string) $this->queryId), 0, 36)
        );
        $diskPath = $tenantUuid.'/'.$filename;

        $stream = fopen($localFilePath, 'r');

        if ($stream === false) {
            throw new RuntimeException('Failed to open local CSV file for upload: '.$localFilePath);
        }

        try {
            $disk->put($diskPath, $stream);
        } finally {
            fclose($stream);
        }

        return $diskPath;
    }

    /**
     * Generate a download URL for the stored file
     */
    private function generateDownloadUrl(Query $query, string $diskPath): string
    {
        $disk = Storage::disk(config('export.disk', 'exports'));
        $expirationMinutes = config('export.link_expiration_minutes', 60);

        if ($this->isLocalDisk()) {
            return $this->generateSignedDownloadUrl($query, $diskPath, $expirationMinutes);
        }

        try {
            return $disk->temporaryUrl($diskPath, now()->addMinutes($expirationMinutes));
        } catch (RuntimeException) {
            return $disk->url($diskPath);
        }
    }

    /**
     * Generate a signed download URL via the download controller
     */
    private function generateSignedDownloadUrl(Query $query, string $diskPath, int $expirationMinutes): string
    {
        $file = basename($diskPath);
        $expires = Carbon::now()
            ->addMinutes($expirationMinutes)
            ->timestamp;
        $signature = DownloadCsvController::generateSignature($query->tenant->uuid, $file, $expires);

        return route('tenant.download', [
            'tenant' => $query->tenant->uuid,
            'file' => $file,
            'expires' => $expires,
            'signature' => $signature,
        ]);
    }

    /**
     * Check if the export disk uses the local filesystem driver
     */
    private function isLocalDisk(): bool
    {
        $diskName = config('export.disk', 'exports');

        return config("filesystems.disks.{$diskName}.driver") === 'local';
    }

    /**
     * Update the ToolCall record with results
     */
    private function updateToolCallRecord(bool $isCompleted, array $responsePayload): void
    {
        if (! $this->toolCallId) {
            return;
        }

        $toolCall = ToolCall::find($this->toolCallId);
        if ($toolCall) {
            $toolCall->update([
                'response_payload' => $responsePayload,
                'is_completed' => $isCompleted,
            ]);
        }
    }

    /**
     * Count CSV data rows (excluding header)
     */
    private function countCsvRows(string $filePath): int
    {
        $lineCount = 0;
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            return 0;
        }

        while (fgets($handle) !== false) {
            $lineCount++;
        }

        fclose($handle);

        // Subtract 1 for the header row
        return max(0, $lineCount - 1);
    }

    /**
     * Load query with relationships
     */
    private function loadQueryWithThread(): Query
    {
        $query = Query::with(['thread', 'tenant.datasources'])->find($this->queryId);

        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $this->queryId);
        }

        return $query;
    }

    /**
     * Validate thread has required fields
     */
    private function validateThread($thread): void
    {
        if (! $thread->channel_id || ! $thread->thread_ts) {
            Log::warning('Thread missing required fields for CSV export', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
            ]);
            throw new \InvalidArgumentException('Thread missing required fields for CSV export');
        }
    }

    private function logJobStart(): void
    {
        Log::info('Starting CSV export job', [
            'query_id' => $this->queryId,
            'sql_call_id' => $this->sqlCallId,
            'async_large_export' => $this->asyncLargeExport,
        ]);
    }

    /**
     * Export CSV data using service
     */
    private function exportCsvData(Query $query): array
    {
        $datasource = $query->tenant->datasources->first();

        return $this->dataExporter->exportData($datasource, $this->sql, $this->parameters, $this->rowLimit);
    }

    /**
     * Generate CSV file using service
     */
    private function generateCsvFile(array $csvData): string
    {
        return $this->fileGenerator->generateFile($csvData, $this->queryId, $this->sqlCallId);
    }

    private function handleEmptyResults(Query $query): void
    {
        Log::info('No data to export, skipping CSV generation', [
            'query_id' => $this->queryId,
            'sql_call_id' => $this->sqlCallId,
        ]);

        try {
            $this->slackUploader->sendEmptyResultsMessage($query->tenant, $query->thread);
        } catch (Throwable $e) {
            Log::error('Failed to post empty results message to Slack', [
                'query_id' => $this->queryId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Upload CSV to Slack
     */
    private function uploadToSlack(Query $query, string $filePath): void
    {
        $this->slackUploader->uploadFile($query->tenant, $query->thread, $filePath);
    }

    private function logJobCompletion(int $rowCount, int $fileSize): void
    {
        Log::info('CSV export completed successfully', [
            'query_id' => $this->queryId,
            'sql_call_id' => $this->sqlCallId,
            'row_count' => $rowCount,
            'file_size' => $fileSize,
            'async_large_export' => $this->asyncLargeExport,
        ]);
    }
}
