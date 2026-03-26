<?php

declare(strict_types=1);

namespace App\Services\Query;

use App\Enums\QueryStatus;
use App\Enums\ToolNames;
use App\Models\ToolCall;

/**
 * Service for calculating final query status based on tool calls
 */
class QueryStatusCalculator
{
    /**
     * Calculate final query status based on tool calls.
     *
     * Scans all tool calls (not just the last) because ghost "not captured"
     * records or duplicate calls can land after the real ones.
     */
    public function calculateFinalStatus(int $queryId): QueryStatus
    {
        $toolCalls = $this->getAllToolCalls($queryId);

        if ($toolCalls->isEmpty()) {
            return QueryStatus::DONE;
        }

        $hasCompletingTool = $toolCalls->contains(function (ToolCall $tc) {
            return $this->isRunSqlQueryTool($tc) || $this->isCsvExportTool($tc);
        });

        return $hasCompletingTool ? QueryStatus::DONE : QueryStatus::INPUT_REQUESTED;
    }

    /**
     * Get all tool calls for a query, ordered newest-first.
     */
    private function getAllToolCalls(int $queryId): \Illuminate\Support\Collection
    {
        return ToolCall::where('query_id', $queryId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Check whether a CSV export was already delivered to Slack for this query.
     *
     * Scans all tool calls because the LLM may call both export_csv and
     * run_query_for_csv_export, or ghost "not captured" records may appear
     * after the real export.
     *
     * Returns true when the Slack notification from notifySlack() should be
     * suppressed because the CSV (or async-queued notification) is already
     * visible in the thread.
     */
    public function wasCsvAlreadyDelivered(int $queryId): bool
    {
        $toolCalls = $this->getAllToolCalls($queryId);

        foreach ($toolCalls as $toolCall) {
            if (! $this->isCsvExportTool($toolCall)) {
                continue;
            }

            $payload = $this->decodeResponsePayload($toolCall);
            $resultKind = $payload['result_kind'] ?? '';

            // Sync success (CSV uploaded during tool execution) or async queued
            // (tool already posted its own Slack notification)
            if (in_array($resultKind, ['csv_export', 'csv_export_async'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if tool call is a SQL query execution
     */
    private function isRunSqlQueryTool(ToolCall $toolCall): bool
    {
        return $toolCall->tool === ToolNames::RUN_SQL_QUERY->value;
    }

    /**
     * Check if tool call is a CSV export operation
     */
    private function isCsvExportTool(ToolCall $toolCall): bool
    {
        return in_array($toolCall->tool, [
            ToolNames::EXPORT_CSV->value,
            ToolNames::RUN_QUERY_FOR_CSV_EXPORT->value,
        ], true);
    }

    /**
     * Decode the response_payload from a tool call
     *
     * @return array<string, mixed>
     */
    private function decodeResponsePayload(ToolCall $toolCall): array
    {
        $payload = $toolCall->response_payload;

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
