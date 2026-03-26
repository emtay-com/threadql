<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use App\Models\ToolCall;
use Illuminate\Support\Collection;

/**
 * Builds the Context Ledger for conversation resumes.
 *
 * Creates a compact, provider-agnostic summary of previous tool calls
 * in a format that helps the LLM understand what has already been done.
 */
class PromptLedgerBuilder
{
    /**
     * Build the Context Ledger for a query.
     *
     * @param int $queryId The query ID to build the ledger for
     * @return string The formatted ledger text, or empty string if no tool calls
     */
    public static function buildForQuery(int $queryId): string
    {
        $toolCalls = self::getToolCallsForQuery($queryId);

        if ($toolCalls->isEmpty()) {
            return '';
        }

        return self::formatLedger($toolCalls);
    }

    /**
     * Get tool calls for a query, limited by configuration.
     */
    private static function getToolCallsForQuery(int $queryId): Collection
    {
        $maxSteps = (int) GeneralSetting::resolve(SettingEnum::LLM_RESUME_MAX_STEPS)->value;

        return ToolCall::where('query_id', $queryId)
            ->where('is_completed', true) // Only include completed tool calls
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($maxSteps)
            ->get();
    }

    /**
     * Format tool calls into the Context Ledger format.
     */
    private static function formatLedger(Collection $toolCalls): string
    {
        $lines = ['Steps so far:'];

        foreach ($toolCalls as $index => $toolCall) {
            $stepNumber = $index + 1;
            $summary = ToolCallSummarizer::summarize($toolCall);

            $lines[] = "{$stepNumber}) Tool: {$toolCall->tool}";
            $lines[] = "   args: {$summary['args']}";
            $lines[] = "   result: {$summary['result']}";
        }

        return implode("\n", $lines);
    }
}
