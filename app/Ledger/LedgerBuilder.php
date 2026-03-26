<?php

declare(strict_types=1);

namespace App\Ledger;

use App\Models\Query;
use App\Models\ToolCall;
use App\Support\Ledger\ToolCallSummarizer;
use Illuminate\Support\Collection;

/**
 * Builds context ledger for follow-up prompts.
 *
 * Creates a compact summary of previous tool calls in a thread,
 * with the ability to exclude the latest run_sql_query call.
 */
class LedgerBuilder
{
    public function __construct(
        private readonly Query $query,
    ) {
    }

    /**
     * Build the context ledger for this query's thread.
     *
     * @param int $maxColumns Maximum columns to show in result summaries
     * @param int $maxText Maximum text length for JSON payloads
     * @return array<string> Array of ledger lines
     */
    public function build(int $maxColumns = 10, int $maxText = 3000): array
    {
        $toolCalls = $this->getThreadToolCalls();

        if ($toolCalls->isEmpty()) {
            return [];
        }

        return $this->formatLedger($toolCalls, $maxColumns, $maxText);
    }

    /**
     * Get all tool calls for this query's thread.
     */
    private function getThreadToolCalls(): Collection
    {
        return ToolCall::whereHas('queryRecord', function ($query) {
            $query->where('thread_id', $this->query->thread_id)
                ->where('id', '<=', $this->query->id);
        })
            ->where('is_completed', true) // Only include completed tool calls
            ->orderBy('id')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Format tool calls into ledger lines.
     */
    private function formatLedger(Collection $toolCalls, int $maxColumns, int $maxText): array
    {
        $lines = [];

        foreach ($toolCalls as $index => $toolCall) {
            $stepNumber = $index + 1;
            $summary = self::summarizeToolCall($toolCall, $maxColumns, $maxText);

            $lines[] = "{$stepNumber}) Tool: {$toolCall->tool}, args: {$summary['args']}, result: {$summary['result']}";
        }

        return $lines;
    }

    /**
     * Summarize a tool call with column and text limits.
     */
    public static function summarizeToolCall(ToolCall $toolCall, int $maxColumns, int $maxText): array
    {
        $summary = ToolCallSummarizer::summarize($toolCall);

        // Apply text limits to the args
        $args = $summary['args'];
        if (strlen($args) > $maxText) {
            $args = substr($args, 0, $maxText).'...';
        }

        // For result summaries that include column info, limit the columns shown
        $result = $summary['result'];
        if (str_contains($result, 'columns=')) {
            $result = self::limitColumnsInResult($result, $maxColumns);
        }

        return [
            'args' => $args,
            'result' => $result,
        ];
    }

    /**
     * Limit the number of columns shown in a result summary.
     */
    private static function limitColumnsInResult(string $result, int $maxColumns): string
    {
        // Extract column list from format like: "rows=187, columns=[id,user_id,amount,created_at]"
        if (preg_match('/columns=\[([^\]]+)\]/', $result, $matches)) {
            $columns = explode(',', $matches[1]);

            if (count($columns) > $maxColumns) {
                $shownColumns = array_slice($columns, 0, $maxColumns);
                $remaining = count($columns) - $maxColumns;
                $columnsStr = implode(',', $shownColumns).",...(+{$remaining} more)";

                return str_replace($matches[0], "columns=[{$columnsStr}]", $result);
            }
        }

        return $result;
    }
}
