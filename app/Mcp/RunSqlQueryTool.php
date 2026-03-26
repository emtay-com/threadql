<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Command\ExecuteParameterizedSelectCommand;
use App\Enums\QueryStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Debug\SqlDebugEcho;
use App\Jobs\NotifyToolExecutingJob;
use App\Jobs\PaginateQueryJob;
use App\Jobs\SendNoResultsMessageJob;
use App\Mcp\ToolResults\RunSqlQueryPayload;
use App\Models\Query;
use App\Models\ToolCall;
use App\Services\Sql\AggregateDetector;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool for executing SQL queries with read-only guarantees
 */
class RunSqlQueryTool extends Tool
{
    protected string $name = 'run_sql_query';

    protected string $description = 'Execute a parameterized SQL SELECT query against a tenant database with read-only safeguards';

    private float $startTime;

    public function __construct(
        private readonly DomainCommandBus $commandBus,
        private readonly AggregateDetector $aggregateDetector,
        private ?SqlDebugEcho $sqlDebugEcho = null
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

        return [
            'query_id' => $queryId,
            'sql' => $sql,
            'parametersJson' => $parametersJson,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $this->startTime = microtime(true);
        [$queryId, $sql, $params] = $this->parseRequest($request);

        if ($error = $this->validateInputs($queryId, $sql, $params)) {
            return $this->respondWithPayload($error);
        }

        $query = Query::with(['thread', 'tenant.datasources'])->find($queryId);
        if ($error = $this->validateQuery($query)) {
            return $this->respondWithPayload($error);
        }

        $toolCall = ToolCall::create([
            'tenant_id' => $query->tenant_id,
            'query_id' => $queryId,
            'tool' => 'run_sql_query',
            'request_payload' => $this->buildRequestPayload($sql, $params),
        ]);

        NotifyToolExecutingJob::dispatch($queryId, 'run_sql_query');

        try {
            if ($this->aggregateDetector->isAggregateQuery($sql)) {
                $result = $this->handleAggregateQuery($query, $queryId, $sql, $params, $toolCall);
                if ($result) {
                    return $result;
                }
            }

            return $this->handleTabularQuery($query, $queryId, $sql, $params, $toolCall);
        } catch (Exception $e) {
            Log::error('Error executing SQL query', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateQueryWithError($query, $e->getMessage());

            return $this->respondWithPayload($this->errorPayload('Database error: '.$e->getMessage()), $toolCall);
        }
    }

    /**
     * Parse and extract input values from the MCP request.
     *
     * @return array{int, string, array}
     */
    private function parseRequest(Request $request): array
    {
        $queryId = (int) $request->get('query_id');
        $sql = (string) $request->get('sql', '');
        $parametersJson = (string) $request->get('parametersJson', '{}');
        $decodedParams = json_decode($parametersJson, true);

        return [$queryId, $sql, is_array($decodedParams) ? $decodedParams : []];
    }

    /**
     * Serialize a payload into an MCP Response, optionally recording it on the ToolCall.
     */
    private function respondWithPayload(RunSqlQueryPayload $payload, ?ToolCall $toolCall = null): Response
    {
        $serialized = $payload->jsonSerialize();

        $toolCall?->update([
            'response_payload' => json_encode($serialized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ]);

        return Response::text(json_encode($serialized));
    }

    /**
     * Shorthand for building an error payload with the current duration.
     */
    private function errorPayload(string $message): RunSqlQueryPayload
    {
        return RunSqlQueryPayload::error($message, [
            'took_ms' => $this->calculateDuration(),
        ]);
    }

    private function validateInputs(int $queryId, string $sql, array $params): ?RunSqlQueryPayload
    {
        if ($queryId <= 0) {
            return RunSqlQueryPayload::error('Invalid query_id provided', [
                'took_ms' => 0,
            ]);
        }

        if (trim($sql) === '') {
            return $this->errorPayload('Invalid SQL provided');
        }

        if (! empty($params) && ! $this->isAssociativeArray($params)) {
            return $this->errorPayload('Parameters must be an object');
        }

        if (! $this->isSelectStatement($sql)) {
            return $this->errorPayload('Only SELECT statements are allowed');
        }

        return null;
    }

    private function validateQuery(?Query $query): ?RunSqlQueryPayload
    {
        if (! $query) {
            return $this->errorPayload('Query not found');
        }

        if (! $query->thread || ! $query->tenant || $query->tenant->datasources->isEmpty()) {
            return $this->errorPayload('Query is missing required relationships');
        }

        return null;
    }

    /**
     * Handle aggregate queries that return a single scalar value.
     *
     * Returns null when the result is not a single scalar cell,
     * signalling that the caller should fall through to the tabular path.
     */
    private function handleAggregateQuery(
        Query $query,
        int $queryId,
        string $sql,
        array $params,
        ToolCall $toolCall
    ): ?Response {
        $command = new ExecuteParameterizedSelectCommand(queryId: $queryId, sql: $sql, parameters: $params);
        $response = $this->commandBus->dispatch($command);

        if (! $response->isSuccess()) {
            return $this->respondWithPayload($this->errorPayload(implode(', ', $response->getErrors())));
        }

        $result = $response->getResult();
        $rows = $result->rows;

        // For aggregates, expect exactly one row with one column
        if (count($rows) !== 1 || count($rows[0]) !== 1) {
            // Not a single scalar cell — fall through to tabular path
            return null;
        }

        $label = array_key_first($rows[0]);
        $value = array_values($rows[0])[0];

        $this->sqlDebugEcho?->maybeSend(
            $query,
            $params,
            $sql,
            $this->calculateDuration(),
            $result->rowCount,
            'database'
        );

        $query->update([
            'status' => QueryStatus::DONE->value,
            'sql_text' => $sql,
            'parameters' => $params,
            'result_meta_json' => [
                'is_aggregate' => true,
                'label' => $label,
                'value' => $value,
                'took_ms' => $this->calculateDuration(),
            ],
        ]);

        $payload = RunSqlQueryPayload::fromAggregate($label, $value, [
            'took_ms' => $this->calculateDuration(),
        ]);

        return $this->respondWithPayload($payload, $toolCall);
    }

    private function handleTabularQuery(
        Query $query,
        int $queryId,
        string $sql,
        array $params,
        ToolCall $toolCall
    ): Response {
        $countCommand = new ExecuteParameterizedSelectCommand(
            queryId: $queryId,
            sql: $sql,
            parameters: $params,
            rowLimit: 1
        );
        $countResponse = $this->commandBus->dispatch($countCommand);

        if (! $countResponse->isSuccess()) {
            return $this->respondWithPayload($this->errorPayload(implode(', ', $countResponse->getErrors())));
        }

        $countResult = $countResponse->getResult();

        $this->sqlDebugEcho?->maybeSend(
            $query,
            $params,
            $sql,
            $this->calculateDuration(),
            $countResult->rowCount,
            'database'
        );

        $query->update([
            'status' => QueryStatus::DONE->value,
            'sql_text' => $sql,
            'parameters' => $params,
            'result_meta_json' => [
                'is_aggregate' => false,
                'took_ms' => $this->calculateDuration(),
            ],
        ]);

        if ($countResult->rowCount === 0) {
            SendNoResultsMessageJob::dispatch($queryId)->delay(2);

            return $this->respondWithPayload(RunSqlQueryPayload::noResults($this->calculateDuration()), $toolCall);
        }

        PaginateQueryJob::dispatch($queryId, 0, 0)->onQueue('default');

        return $this->respondWithPayload(
            RunSqlQueryPayload::pendingTable(
                'Resultset will be posted in the Slack thread.',
                [
                    'took_ms' => $this->calculateDuration(),
                ]
            ),
            $toolCall
        );
    }

    /**
     * Build a masked request payload for tool call logging.
     */
    private function buildRequestPayload(string $sql, array $params): string
    {
        $maskedParams = $this->maskSensitiveParameters($params);

        $payload = [
            'sql' => $sql,
            'parameters' => $maskedParams,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Mask sensitive information in parameters.
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
     * Update the query with error information.
     */
    private function updateQueryWithError(Query $query, string $errorMessage): void
    {
        $query->update([
            'status' => QueryStatus::ERROR->value,
            'result_meta_json' => [
                'error' => $errorMessage,
            ],
        ]);
    }

    /**
     * Calculate execution duration in milliseconds.
     */
    private function calculateDuration(): int
    {
        return (int) ((microtime(true) - $this->startTime) * 1000);
    }

    /**
     * Check if the SQL is a SELECT statement.
     */
    private function isSelectStatement(string $sql): bool
    {
        $normalized = strtoupper(trim($sql));

        return str_starts_with($normalized, 'SELECT');
    }

    /**
     * Check if an array is associative.
     */
    private function isAssociativeArray(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }
}
