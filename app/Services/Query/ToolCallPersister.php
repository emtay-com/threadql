<?php

declare(strict_types=1);

namespace App\Services\Query;

use App\Models\ToolCall;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for persisting tool call metadata from LLM responses
 */
class ToolCallPersister
{
    /**
     * Persist function_call_id and result_id from Prism response to tool calls
     *
     * @param array $prismToolCalls Tool calls from Prism response
     * @param int $queryId The query ID
     */
    public function persistToolCallIds(array $prismToolCalls, int $queryId): void
    {
        if (empty($prismToolCalls)) {
            return;
        }

        Log::info('Prism tool calls received', [
            'query_id' => $queryId,
            'tool_calls' => $prismToolCalls,
        ]);

        $dbToolCalls = $this->loadExistingToolCalls($queryId);

        if ($dbToolCalls->isEmpty()) {
            $this->logNoToolCallsFound($queryId, count($prismToolCalls));

            return;
        }

        $this->mapAndUpdateToolCalls($prismToolCalls, $dbToolCalls, $queryId);
    }

    /**
     * Create ToolCall records for any Prism tool calls that have no matching DB record
     *
     * @param array $prismToolCalls Tool calls from Prism response
     * @param int $queryId The query ID
     * @param int $tenantId The tenant ID
     */
    public function createMissingToolCallRecords(array $prismToolCalls, int $queryId, int $tenantId): void
    {
        if (empty($prismToolCalls)) {
            return;
        }

        $dbToolCalls = $this->loadExistingToolCalls($queryId);

        foreach ($prismToolCalls as $prismToolCall) {
            $prismName = $prismToolCall['name'] ?? '';
            $prismId = $prismToolCall['id'] ?? null;

            $hasMatch = $dbToolCalls->contains(function (ToolCall $dbToolCall) use ($prismName, $prismId) {
                return $dbToolCall->function_call_id === $prismId
                    || Str::contains($prismName, $dbToolCall->tool);
            });

            if (! $hasMatch) {
                ToolCall::create([
                    'tenant_id' => $tenantId,
                    'query_id' => $queryId,
                    'tool' => $this->extractShortToolName($prismName),
                    'function_call_id' => $prismId,
                    'result_id' => $prismToolCall['result_id'] ?? null,
                    'request_payload' => $prismToolCall['arguments'] ?? [],
                    'response_payload' => [
                        'error' => 'Tool call not captured by MCP transport layer',
                    ],
                    'is_completed' => false,
                ]);

                Log::warning('Created missing ToolCall record for unmatched Prism tool call', [
                    'query_id' => $queryId,
                    'tenant_id' => $tenantId,
                    'tool_name' => $prismName,
                    'function_call_id' => $prismId,
                ]);
            }
        }
    }

    /**
     * Load existing tool calls for query
     */
    private function loadExistingToolCalls(int $queryId): Collection
    {
        return ToolCall::where('query_id', $queryId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Map Prism tool calls to DB rows by name matching and update
     */
    private function mapAndUpdateToolCalls(array $prismToolCalls, Collection $dbToolCalls, int $queryId): void
    {
        // Build pool of unmatched DB tool calls (those without function_call_id)
        $unmatchedPool = $dbToolCalls->filter(fn (ToolCall $tc) => $tc->function_call_id === null)
            ->values();

        foreach ($prismToolCalls as $prismToolCall) {
            $prismName = $prismToolCall['name'] ?? '';

            // Find candidates by name match
            $candidates = $unmatchedPool->filter(fn (ToolCall $tc) => Str::contains($prismName, $tc->tool));

            if ($candidates->isEmpty()) {
                Log::warning('No matching DB tool call found for Prism tool call', [
                    'query_id' => $queryId,
                    'prism_tool_name' => $prismName,
                    'prism_function_call_id' => $prismToolCall['id'] ?? null,
                ]);

                continue;
            }

            // If multiple candidates with same name, use request_payload tiebreaker
            $match = $candidates->count() > 1
                ? $this->findBestMatch($candidates, $prismToolCall) ?? $candidates->first()
                : $candidates->first();

            $this->updateToolCall($match, $prismToolCall);

            // Remove matched record from pool
            $unmatchedPool = $unmatchedPool->reject(fn (ToolCall $tc) => $tc->id === $match->id)
                ->values();
        }
    }

    /**
     * Find the best matching DB tool call when multiple candidates exist
     *
     * Uses request_payload content (SQL text, table names) as tiebreaker
     */
    private function findBestMatch(Collection $candidates, array $prismToolCall): ?ToolCall
    {
        $prismArgs = $prismToolCall['arguments'] ?? [];
        if (empty($prismArgs)) {
            return null;
        }

        $prismArgsJson = json_encode($prismArgs);

        $bestMatch = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $requestPayload = $candidate->request_payload ?? [];
            if (empty($requestPayload)) {
                continue;
            }

            $dbPayloadJson = json_encode($requestPayload);

            // Score based on shared content between payloads
            $score = similar_text($prismArgsJson, $dbPayloadJson);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $candidate;
            }
        }

        return $bestMatch;
    }

    /**
     * Extract short tool name from fully-qualified MCP tool name
     */
    private function extractShortToolName(string $fullName): string
    {
        // MCP tool names may be namespaced like "relay__app__run_sql_query" or "app.run_sql_query"
        if (str_contains($fullName, '__')) {
            $parts = explode('__', $fullName);

            return end($parts) ?: $fullName;
        }

        $parts = explode('.', $fullName);

        return end($parts) ?: $fullName;
    }

    /**
     * Update tool call with IDs from Prism
     */
    private function updateToolCall(ToolCall $dbToolCall, array $prismToolCall): void
    {
        $dbToolCall->update([
            'function_call_id' => $prismToolCall['id'],
            'result_id' => $prismToolCall['result_id'],
        ]);

        Log::info('Updated tool call with function_call_id and result_id', [
            'tool_call_id' => $dbToolCall->id,
            'function_call_id' => $prismToolCall['id'],
            'result_id' => $prismToolCall['result_id'],
        ]);
    }

    /**
     * Log warning when no tool calls found
     */
    private function logNoToolCallsFound(int $queryId, int $prismCount): void
    {
        Log::warning('No tool calls found in DB to update with function_call_id', [
            'query_id' => $queryId,
            'prism_tool_calls_count' => $prismCount,
        ]);
    }
}
