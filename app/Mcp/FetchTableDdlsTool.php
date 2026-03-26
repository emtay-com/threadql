<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Jobs\NotifyToolExecutingJob;
use App\Mcp\ToolResults\FetchTableDdlsPayload;
use App\Models\Query;
use App\Models\Table;
use App\Models\ToolCall;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool for fetching DDLs of tables not already included in the prompt
 */
class FetchTableDdlsTool extends Tool
{
    protected string $name = 'fetch_table_ddls';

    protected string $description = 'Fetch DDLs for tables by name';

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
        /** @var JsonSchema $tables */
        $tables = $schema->string()
            ->description('Comma-separated list of table names to fetch DDLs for');

        return [
            'query_id' => $queryId,
            'tables' => $tables,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $toolCall = null;
        $queryId = (int) $request->get('query_id');
        $tables = (string) $request->get('tables', '');

        try {
            // Validate inputs
            if ($queryId <= 0) {
                $payload = FetchTableDdlsPayload::error('Invalid query_id provided');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            if (trim($tables) === '') {
                $payload = FetchTableDdlsPayload::error('Invalid tables provided');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            // Find the query and validate it belongs to a thread and tenant
            $query = Query::with(['thread', 'tenant'])->find($queryId);
            if (! $query) {
                $payload = FetchTableDdlsPayload::error('Query not found');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            if (! $query->thread || ! $query->tenant) {
                $payload = FetchTableDdlsPayload::error('Query is missing required relationships');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            // Parse and validate table names
            $tableNames = $this->parseTableNames($tables);
            if (empty($tableNames)) {
                $payload = FetchTableDdlsPayload::error('No valid table names provided');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            // Create tool call record for logging
            $toolCall = ToolCall::create([
                'tenant_id' => $query->tenant_id,
                'query_id' => $queryId,
                'tool' => 'fetch_table_ddls',
                'request_payload' => json_encode([
                    'query_id' => $queryId,
                    'tables' => $tables,
                    'parsed_tables' => $tableNames,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);

            // Post progress notification (async)
            NotifyToolExecutingJob::dispatch($queryId, 'fetch_table_ddls');

            // Fetch DDLs for the specified tables
            $result = $this->fetchDdls($query->tenant_id, $tableNames);

            // Create success payload
            $payload = FetchTableDdlsPayload::success($query->tenant_id, $queryId, $tableNames, $result);

            // Update tool call with response
            if ($toolCall) {
                $toolCall->update([
                    'response_payload' => json_encode(
                        $payload->jsonSerialize(),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            return Response::text(json_encode($payload->jsonSerialize()));

        } catch (Exception $e) {
            Log::error('Error fetching table DDLs', [
                'query_id' => $queryId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Create error payload
            $payload = FetchTableDdlsPayload::error('Database error: '.$e->getMessage());

            // Update tool call with error response
            if ($toolCall) {
                $toolCall->update([
                    'response_payload' => json_encode(
                        $payload->jsonSerialize(),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            return Response::text(json_encode($payload->jsonSerialize()));
        }
    }

    /**
     * Parse comma-separated table names into a deduplicated array
     */
    private function parseTableNames(string $tables): array
    {
        $names = explode(',', $tables);
        $parsed = [];

        foreach ($names as $name) {
            $trimmed = trim(strtolower($name));
            if ($trimmed !== '' && ! in_array($trimmed, $parsed, true)) {
                $parsed[] = $trimmed;
            }
        }

        return $parsed;
    }

    /**
     * Fetch DDLs for the specified table names
     */
    private function fetchDdls(int $tenantId, array $tableNames): array
    {
        $maxTables = config('llm.max_ddl_tables_per_call', 20);
        $maxChars = config('llm.max_ddl_chars', 32768);

        // Limit the number of tables if needed
        $requestedTables = $tableNames;
        $truncated = false;
        if (count($tableNames) > $maxTables) {
            $tableNames = array_slice($tableNames, 0, $maxTables);
            $truncated = true;
        }

        // Fetch tables from database
        $tables = Table::where('tenant_id', $tenantId)
            ->whereIn('name', $tableNames)
            ->orderBy('name')
            ->get();

        $found = [];
        $missing = [];
        $skipped = [];

        foreach ($tableNames as $tableName) {
            $table = $tables->firstWhere('name', $tableName);

            if (! $table) {
                $missing[] = $tableName;

                continue;
            }

            if (! $table->ddl_sql) {
                $missing[] = $tableName;

                continue;
            }

            $ddl = $table->ddl_sql;
            $ddlTruncated = false;

            // Check if DDL needs truncation
            if (strlen($ddl) > $maxChars) {
                $ddl = substr($ddl, 0, $maxChars);
                $ddlTruncated = true;
            }

            $found[] = [
                'table' => $table->name,
                'priority' => $table->priority,
                'row_count' => $table->row_count,
                'size_mb' => $table->size_mb,
                'ddl' => $ddl,
                'ddl_truncated' => $ddlTruncated,
            ];
        }

        return [
            'found' => $found,
            'missing' => $missing,
            'skipped' => $skipped,
            'truncated' => $truncated,
        ];
    }
}
