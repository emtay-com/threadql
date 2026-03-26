<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Domain\Export\ExportCsvService as DomainExportCsvService;
use App\Enums\QueryStatus;
use App\Enums\SettingEnum;
use App\Infrastructure\Debug\SqlDebugEcho;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\ExportCsvAndDeliverJob;
use App\Jobs\NotifyToolExecutingJob;
use App\Mcp\ToolResults\CsvExportAcceptedResult;
use App\Mcp\ToolResults\CsvExportAsyncAcceptedResult;
use App\Mcp\ToolResults\CsvExportDeniedResult;
use App\Mcp\ToolResults\CsvExportFailedResult;
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
 * MCP Tool for executing parameterized SELECT queries and exporting results to CSV
 */
class RunQueryForCsvExportTool extends Tool
{
    protected string $name = 'run_query_for_csv_export';

    protected string $description = 'Compose a parameterized SELECT and export its result to CSV';

    private float $startTime;

    public function __construct(
        private readonly DomainExportCsvService $domainExportCsvService,
        private readonly SlackMessenger $slackMessenger,
        private readonly ?SqlDebugEcho $sqlDebugEcho = null
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
            ->description('The query ID to execute against');
        /** @var JsonSchema $sql */
        $sql = $schema->string()
            ->description('The SQL SELECT statement to execute');
        /** @var JsonSchema $parametersJson */
        $parametersJson = $schema->string()
            ->description('JSON-encoded parameters for the query');
        /** @var JsonSchema $rowLimit */
        $rowLimit = $schema->integer()
            ->description('Maximum number of rows to export');

        return [
            'query_id' => $queryId,
            'sql' => $sql,
            'parametersJson' => $parametersJson,
            'row_limit' => $rowLimit,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $this->startTime = microtime(true);
        $toolCall = null;

        ['queryId' => $queryId, 'sql' => $sql, 'params' => $params, 'rowLimit' => $rowLimit] = $this->extractInput(
            $request
        );

        try {
            if ($error = $this->validateInput($queryId, $sql, $params)) {
                return $error;
            }

            $queryOrError = $this->loadAndValidateQuery($queryId);
            if ($queryOrError instanceof Response) {
                return $queryOrError;
            }
            $query = $queryOrError;

            $rowCount = $this->domainExportCsvService->getRowCount($query, $sql, $params);
            $maxSyncRows = (int) GeneralSetting::resolve(SettingEnum::MAX_ROWS_INLINE_CSV)->value;
            $maxAsyncRows = (int) config('export.max_rows_async_export', 2000000);

            if ($rowCount > $maxAsyncRows) {
                return $this->handleDeniedExport($query, $sql, $params, $rowLimit, $rowCount, $maxAsyncRows);
            }

            if ($rowCount > $maxSyncRows) {
                return $this->handleAsyncExport($query, $sql, $params, $rowLimit, $rowCount);
            }

            return $this->handleSyncExport($query, $sql, $params, $rowLimit, $rowCount, $toolCall);

        } catch (Exception $e) {
            Log::error('Error in CSV export tool', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($toolCall) {
                $toolCall->update([
                    'is_completed' => false,
                ]);
            }

            return Response::text(json_encode(CsvExportFailedResult::unexpected($e->getMessage())));
        }
    }

    /**
     * Extract and decode input parameters from the request.
     *
     * @return array{queryId: int, sql: string, params: array<string, mixed>, rowLimit: int|null}
     */
    private function extractInput(Request $request): array
    {
        $queryId = (int) $request->get('query_id');
        $sql = (string) $request->get('sql', '');
        $parametersJson = (string) $request->get('parametersJson', '{}');
        $rowLimit = $request->has('row_limit') ? (int) $request->get('row_limit') : null;

        $decodedParams = json_decode($parametersJson, true);
        $params = is_array($decodedParams) ? $decodedParams : [];

        return [
            'queryId' => $queryId,
            'sql' => $sql,
            'params' => $params,
            'rowLimit' => $rowLimit,
        ];
    }

    /**
     * Validate the parsed input values.
     *
     * @param  array<string, mixed>  $params
     * @return Response|null Error response on failure, null on success
     */
    private function validateInput(int $queryId, string $sql, array $params): ?Response
    {
        if ($queryId <= 0) {
            return Response::text(json_encode(CsvExportFailedResult::unexpected('Invalid query_id provided')));
        }

        if (trim($sql) === '') {
            return Response::text(json_encode(CsvExportFailedResult::unexpected('Invalid SQL provided')));
        }

        if (! $this->isSelectStatement($sql)) {
            return Response::text(
                json_encode(CsvExportFailedResult::unexpected('Only SELECT statements are allowed'))
            );
        }

        if (! empty($params) && ! $this->isAssociativeArray($params)) {
            return Response::text(json_encode(CsvExportFailedResult::unexpected('Parameters must be an object')));
        }

        return null;
    }

    /**
     * Load the Query with relationships and validate it has required associations.
     *
     * @return Query|Response The loaded Query on success, or an error Response
     */
    private function loadAndValidateQuery(int $queryId): Query|Response
    {
        $query = Query::with(['thread', 'tenant.datasources'])->find($queryId);
        if (! $query) {
            return Response::text(json_encode(CsvExportFailedResult::unexpected('Query not found')));
        }

        if (! $query->thread || ! $query->tenant || $query->tenant->datasources->isEmpty()) {
            return Response::text(
                json_encode(CsvExportFailedResult::unexpected('Query is missing required relationships'))
            );
        }

        return $query;
    }

    /**
     * Handle an export that exceeds the async row limit — deny entirely.
     *
     * @param  array<string, mixed>  $params
     */
    private function handleDeniedExport(
        Query $query,
        string $sql,
        array $params,
        ?int $rowLimit,
        int $rowCount,
        int $maxAsyncRows
    ): Response {
        ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $query->id,
            'tool' => 'run_query_for_csv_export',
            'request_payload' => $this->buildRequestPayload($sql, $params, $rowLimit),
            'response_payload' => json_encode(
                CsvExportDeniedResult::limitExceeded($rowCount, $maxAsyncRows),
                JSON_PRETTY_PRINT
            ),
            'is_completed' => true,
        ]);

        return Response::text(json_encode(CsvExportDeniedResult::limitExceeded($rowCount, $maxAsyncRows)));
    }

    /**
     * Handle an export between sync and async limits — dispatch async job.
     *
     * @param  array<string, mixed>  $params
     */
    private function handleAsyncExport(
        Query $query,
        string $sql,
        array $params,
        ?int $rowLimit,
        int $rowCount
    ): Response {
        $exportToolCall = ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $query->id,
            'tool' => 'run_query_for_csv_export',
            'request_payload' => $this->buildRequestPayload($sql, $params, $rowLimit),
            'response_payload' => json_encode(CsvExportAsyncAcceptedResult::fromMeta($rowCount), JSON_PRETTY_PRINT),
            'is_completed' => false,
        ]);

        ExportCsvAndDeliverJob::dispatch($query->id, 0, $sql, $params, $rowLimit, true, $exportToolCall->id);

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
                'query_id' => $query->id,
                'error' => $e->getMessage(),
            ]);
        }

        return Response::text(json_encode(CsvExportAsyncAcceptedResult::fromMeta($rowCount)));
    }

    /**
     * Handle an export within sync limits — execute synchronously.
     *
     * @param  array<string, mixed>  $params
     */
    private function handleSyncExport(
        Query $query,
        string $sql,
        array $params,
        ?int $rowLimit,
        int $rowCount,
        ?ToolCall &$toolCall
    ): Response {
        $toolCall = ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $query->id,
            'tool' => 'run_query_for_csv_export',
            'request_payload' => $this->buildRequestPayload($sql, $params, $rowLimit),
            'is_completed' => false,
        ]);

        NotifyToolExecutingJob::dispatch($query->id, 'run_query_for_csv_export');

        try {
            $this->domainExportCsvService->exportFullQueryToCsv($query, $sql, $params, $rowCount);

            $this->sqlDebugEcho?->maybeSend(
                $query,
                $params,
                $sql,
                $this->calculateDuration(),
                $rowCount,
                'database'
            );

            $toolCall->update([
                'response_payload' => json_encode(CsvExportAcceptedResult::fromMeta($rowCount), JSON_PRETTY_PRINT),
                'is_completed' => true,
            ]);

            $query->update([
                'status' => QueryStatus::DONE->value,
                'sql_text' => $sql,
                'parameters' => $params,
                'result_meta_json' => [
                    'is_aggregate' => false,
                    'took_ms' => $this->calculateDuration(),
                    'row_count' => $rowCount,
                    'export' => 'csv_sync',
                ],
            ]);

            return Response::text(json_encode(CsvExportAcceptedResult::fromMeta($rowCount)));

        } catch (Exception $exportException) {
            $toolCall->update([
                'response_payload' => json_encode(
                    CsvExportFailedResult::unexpected($exportException->getMessage()),
                    JSON_PRETTY_PRINT
                ),
                'is_completed' => false,
            ]);

            return Response::text(json_encode(CsvExportFailedResult::unexpected($exportException->getMessage())));
        }
    }

    /**
     * Build a masked request payload for tool call logging
     */
    private function buildRequestPayload(string $sql, array $params, ?int $rowLimit): string
    {
        // Mask sensitive information in parameters
        $maskedParams = $this->maskSensitiveParameters($params);

        $payload = [
            'sql' => $sql,
            'parameters' => $maskedParams,
            'row_limit' => $rowLimit,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Mask sensitive information in parameters
     */
    private function maskSensitiveParameters(array $params): array
    {
        $masked = [];
        $sensitiveKeys = ['password', 'secret', 'key', 'token', 'auth'];

        foreach ($params as $key => $value) {
            $lowerKey = strtolower($key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (str_contains($lowerKey, $sensitiveKey)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $masked[$key] = '***masked***';
            } else {
                $masked[$key] = $value;
            }
        }

        return $masked;
    }

    /**
     * Calculate execution duration in milliseconds
     */
    private function calculateDuration(): int
    {
        return (int) ((microtime(true) - $this->startTime) * 1000);
    }

    /**
     * Check if an array is associative
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Check if the SQL is a SELECT statement
     */
    private function isSelectStatement(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));

        return str_starts_with($normalized, 'SELECT');
    }
}
