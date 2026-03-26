<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Export\ExportCsvService as DomainExportCsvService;
use App\Enums\QueryStatus;
use App\Enums\SettingEnum;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\ExportCsvAndDeliverJob;
use App\Mcp\ToolResults\CsvExportAcceptedResult;
use App\Mcp\ToolResults\CsvExportAsyncAcceptedResult;
use App\Mcp\ToolResults\CsvExportDeniedResult;
use App\Mcp\ToolResults\CsvExportFailedResult;
use App\Mcp\ToolResults\ExportCsvResult;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\ToolCall;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool for exporting SQL query results to CSV and delivering via Slack
 */
class ExportCsvTool extends Tool
{
    protected string $name = 'export_csv';

    protected string $description = 'Export the result set of a previously executed run_sql_query call to CSV';

    public function __construct(
        private readonly DomainExportCsvService $domainExportCsvService,
        private readonly SlackMessenger $slackMessenger,
    ) {
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        /** @var JsonSchema $queryId */
        $queryId = $schema->integer()
            ->description('The query ID');
        /** @var JsonSchema $sqlCallId */
        $sqlCallId = $schema->integer()
            ->description('The ID of the previous run_sql_query tool call');
        /** @var JsonSchema $rowLimit */
        $rowLimit = $schema->integer()
            ->description('Maximum number of rows to export');

        return [
            'query_id' => $queryId,
            'sql_call_id' => $sqlCallId,
            'row_limit' => $rowLimit,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $toolCall = null;
        $queryId = (int) $request->get('query_id');
        $sqlCallId = (int) $request->get('sql_call_id');
        $rowLimit = $request->has('row_limit') ? (int) $request->get('row_limit') : null;

        try {
            $validationError = $this->validateInputs($queryId, $sqlCallId);
            if ($validationError) {
                return Response::text(json_encode($validationError));
            }

            $query = $this->loadAndValidateQuery($queryId);
            if (is_array($query)) {
                return Response::text(json_encode($query));
            }

            $toolCallData = $this->loadAndValidateToolCall($sqlCallId);
            if (isset($toolCallData['ok'])) {
                return Response::text(json_encode($toolCallData));
            }

            ['toolCall' => $toolCallRecord, 'rowCount' => $rowCount] = $toolCallData;

            $sqlData = $this->extractSqlFromToolCall($toolCallRecord);
            if (isset($sqlData['ok'])) {
                return Response::text(json_encode($sqlData));
            }

            ['sql' => $sql, 'parameters' => $parameters] = $sqlData;

            // pending_table results from run_sql_query don't include row_count;
            // compute it from the SQL so the sync/async/deny tier decision works.
            if ($rowCount === null) {
                $rowCount = $this->domainExportCsvService->getRowCount($query, $sql, $parameters);
            }

            $limitCheckResult = $this->checkRowLimit(
                $query,
                $queryId,
                $sqlCallId,
                $rowLimit,
                $rowCount,
                $toolCallRecord
            );
            if ($limitCheckResult) {
                return Response::text(json_encode($limitCheckResult));
            }

            $toolCall = $this->createToolCallRecord($query, $queryId, $sqlCallId, $rowLimit);

            $result = $this->performExport($query, $sql, $parameters, $rowCount, $toolCall);

            return Response::text(json_encode($result));

        } catch (Exception $e) {
            $result = $this->handleExportFailure($e, $queryId, $sqlCallId, $toolCall);

            return Response::text(json_encode($result));
        }
    }

    /**
     * Validate input parameters
     *
     * @return array|null Error result or null if valid
     */
    private function validateInputs(int $queryId, int $sqlCallId): ?array
    {
        if ($queryId <= 0) {
            return ExportCsvResult::fromArray(CsvExportFailedResult::unexpected('Invalid query_id provided'));
        }

        if ($sqlCallId <= 0) {
            return ExportCsvResult::fromArray(CsvExportFailedResult::unexpected('Invalid sql_call_id provided'));
        }

        return null;
    }

    /**
     * Load and validate the query with relationships
     *
     * @return Query|array Query model or error result
     */
    private function loadAndValidateQuery(int $queryId): Query|array
    {
        $query = Query::with(['thread', 'tenant.datasources'])->find($queryId);

        if (! $query) {
            return ExportCsvResult::fromArray(CsvExportFailedResult::unexpected('Query not found'));
        }

        if (! $query->thread || ! $query->tenant || $query->tenant->datasources->isEmpty()) {
            return ExportCsvResult::fromArray(
                CsvExportFailedResult::unexpected('Query is missing required relationships')
            );
        }

        return $query;
    }

    /**
     * Load and validate the tool call record
     *
     * @return array Tool call data or error result
     */
    private function loadAndValidateToolCall(int $sqlCallId): array
    {
        $toolCallRecord = ToolCall::where('id', $sqlCallId)
            ->whereIn('tool', ['run_sql_query', 'run_query_for_csv_export'])
            ->where('is_completed', true)
            ->first();

        if (! $toolCallRecord) {
            return ExportCsvResult::fromArray(
                CsvExportFailedResult::unexpected('Invalid sql_call_id: tool call not found or incomplete')
            );
        }

        $responsePayload = $this->decodePayload($toolCallRecord->response_payload);
        $rowCount = $responsePayload['row_count'] ?? null;

        return [
            'toolCall' => $toolCallRecord,
            'rowCount' => $rowCount,
        ];
    }

    /**
     * Check row count against configured limits (three-tier: sync / async / deny)
     *
     * @return array|null Result array if handled (async or denied), null to proceed with sync export
     */
    private function checkRowLimit(
        Query $query,
        int $queryId,
        int $sqlCallId,
        int $rowLimit,
        int $rowCount,
        ToolCall $toolCallRecord,
    ): ?array {
        $maxSyncRows = (int) GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV)->value;
        $maxAsyncRows = config('export.max_rows_async_export', 2000000);

        // Within sync limit — proceed with synchronous export
        if ($rowCount <= $maxSyncRows) {
            return null;
        }

        // Exceeds async limit — deny entirely
        if ($rowCount > $maxAsyncRows) {
            ToolCall::create([
                'tenant_id' => $query->tenant_id,
                'query_id' => $queryId,
                'tool' => 'export_csv',
                'request_payload' => $this->buildRequestPayload($queryId, $sqlCallId, $rowLimit),
                'response_payload' => json_encode(
                    CsvExportDeniedResult::limitExceeded($rowCount, $maxAsyncRows),
                    JSON_PRETTY_PRINT
                ),
                'is_completed' => true,
            ]);

            return ExportCsvResult::fromArray(CsvExportDeniedResult::limitExceeded($rowCount, $maxAsyncRows));
        }

        // Between sync and async limits — dispatch async job
        return $this->dispatchAsyncExport($query, $queryId, $sqlCallId, $rowLimit, $rowCount, $toolCallRecord);
    }

    /**
     * Dispatch an async large export job
     */
    private function dispatchAsyncExport(
        Query $query,
        int $queryId,
        int $sqlCallId,
        int $rowLimit,
        int $rowCount,
        ToolCall $toolCallRecord,
    ): array {
        $sqlData = $this->extractSqlFromToolCall($toolCallRecord);
        if (isset($sqlData['ok'])) {
            return $sqlData;
        }

        ['sql' => $sql, 'parameters' => $parameters] = $sqlData;

        // Create tool call record for the async export (incomplete until job finishes)
        $exportToolCall = ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $queryId,
            'tool' => 'export_csv',
            'request_payload' => $this->buildRequestPayload($queryId, $sqlCallId, $rowLimit),
            'response_payload' => json_encode(CsvExportAsyncAcceptedResult::fromMeta($rowCount), JSON_PRETTY_PRINT),
            'is_completed' => false,
        ]);

        ExportCsvAndDeliverJob::dispatch(
            $queryId,
            $sqlCallId,
            $sql,
            $parameters,
            $rowLimit,
            true,
            $exportToolCall->id,
        );

        // Notify user in Slack thread that the export is processing
        try {
            $this->slackMessenger->replyInThread(
                $query->tenant,
                $query->thread->channel_id,
                $query->thread->thread_ts,
                sprintf(
                    'Large CSV export queued (%s rows). A download link will be posted here when ready.',
                    number_format($rowCount),
                ),
            );
        } catch (Exception $e) {
            Log::warning('Failed to send async export notification to Slack', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
            ]);
        }

        return ExportCsvResult::fromArray(CsvExportAsyncAcceptedResult::fromMeta($rowCount));
    }

    /**
     * Extract SQL and parameters from tool call
     */
    private function extractSqlFromToolCall(ToolCall $toolCallRecord): array
    {
        $requestPayload = $this->decodePayload($toolCallRecord->request_payload);

        if (! $requestPayload || ! isset($requestPayload['sql'])) {
            return ExportCsvResult::fromArray(
                CsvExportFailedResult::unexpected('Invalid tool call request payload')
            );
        }

        return [
            'sql' => $requestPayload['sql'],
            'parameters' => $requestPayload['parameters'] ?? [],
        ];
    }

    /**
     * Create a tool call record for export operation
     */
    private function createToolCallRecord(Query $query, int $queryId, int $sqlCallId, int $rowLimit): ToolCall
    {
        return ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $queryId,
            'tool' => 'export_csv',
            'request_payload' => $this->buildRequestPayload($queryId, $sqlCallId, $rowLimit),
            'is_completed' => false, // Will be marked true after successful delivery
        ]);
    }

    /**
     * Perform the CSV export operation
     */
    private function performExport(
        Query $query,
        string $sql,
        array $parameters,
        int $rowCount,
        ToolCall $toolCall
    ): array {
        try {
            $this->domainExportCsvService->exportFullQueryToCsv($query, $sql, $parameters, $rowCount);

            // Mark as completed on success
            $toolCall->update([
                'response_payload' => json_encode(CsvExportAcceptedResult::fromMeta($rowCount), JSON_PRETTY_PRINT),
                'is_completed' => true,
            ]);

            $query->update([
                'status' => QueryStatus::DONE->value,
                'sql_text' => $sql,
                'parameters' => $parameters,
                'result_meta_json' => [
                    'is_aggregate' => false,
                    'row_count' => $rowCount,
                    'export' => 'csv_sync',
                ],
            ]);

            return ExportCsvResult::fromArray(CsvExportAcceptedResult::fromMeta($rowCount));

        } catch (Exception $exportException) {
            // Mark as not completed on failure
            $toolCall->update([
                'response_payload' => json_encode(
                    CsvExportFailedResult::unexpected($exportException->getMessage()),
                    JSON_PRETTY_PRINT
                ),
                'is_completed' => false,
            ]);

            return ExportCsvResult::fromArray(CsvExportFailedResult::unexpected($exportException->getMessage()));
        }
    }

    /**
     * Handle export failure by logging and cleanup
     */
    private function handleExportFailure(Exception $e, int $queryId, int $sqlCallId, ?ToolCall $toolCall): array
    {
        Log::error('Error in CSV export tool', [
            'query_id' => $queryId,
            'sql_call_id' => $sqlCallId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        // Mark tool call as failed if it was created
        if ($toolCall) {
            $toolCall->update([
                'is_completed' => false,
            ]);
        }

        return ExportCsvResult::fromArray(CsvExportFailedResult::unexpected($e->getMessage()));
    }

    /**
     * Build a request payload for tool call logging
     */
    private function buildRequestPayload(int $queryId, int $sqlCallId, ?int $rowLimit): string
    {
        $payload = [
            'query_id' => $queryId,
            'sql_call_id' => $sqlCallId,
            'row_limit' => $rowLimit,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }
}
