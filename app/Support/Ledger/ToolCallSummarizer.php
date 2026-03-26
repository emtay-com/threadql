<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use App\Models\ToolCall;

/**
 * Summarizes tool calls for the Context Ledger format.
 *
 * Provides compact, human-readable summaries of tool executions
 * for use in conversation resumes.
 */
class ToolCallSummarizer
{
    /**
     * Summarize a tool call into args and result lines for the ledger.
     *
     * @param ToolCall $toolCall The tool call to summarize
     * @return array{args: string, result: string} Array with 'args' and 'result' keys
     */
    public static function summarize(ToolCall $toolCall): array
    {
        $args = self::summarizeArgs($toolCall);
        $result = self::summarizeResult($toolCall);

        return [
            'args' => $args,
            'result' => $result,
        ];
    }

    /**
     * Summarize the arguments of a tool call.
     */
    private static function summarizeArgs(ToolCall $toolCall): string
    {
        /** @var mixed $payload */
        $payload = $toolCall->request_payload;

        if (empty($payload)) {
            return '{}';
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            } else {
                return $payload;
            }
        }

        if (! is_array($payload)) {
            return (string) $payload;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Truncate if too long
        $maxLen = config('llm.resume.max_args_len', 200);
        if (strlen($json) > $maxLen) {
            // For long JSON, just show the keys
            $keys = array_keys($payload);
            $keySummaries = array_map(static fn ($key) => "\"{$key}\": ...", $keys);
            $json = '{'.implode(', ', $keySummaries).'}';
        }

        return $json;
    }

    /**
     * Summarize the result of a tool call.
     */
    private static function summarizeResult(ToolCall $toolCall): string
    {
        /** @var mixed $payload */
        $payload = $toolCall->response_payload;

        if (empty($payload)) {
            return 'no result';
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            } else {
                return $payload;
            }
        }

        if (! is_array($payload)) {
            return 'done';
        }

        // Route to specific summarizers based on tool type
        return match ($toolCall->tool) {
            'fetch_table_ddls' => self::summarizeFetchTableDdls($payload),
            'run_sql_query' => self::summarizeRunSqlQuery($payload),
            'request_definition' => self::summarizeRequestDefinition($payload),
            'parse_definition' => self::summarizeParseDefinition($payload),
            'create_definition' => self::summarizeCreateDefinition($payload),
            default => self::summarizeUnknownTool($payload),
        };
    }

    /**
     * Summarize fetch_table_ddls results.
     */
    private static function summarizeFetchTableDdls(array $payload): string
    {
        $found = $payload['found'] ?? [];
        $missing = $payload['missing'] ?? [];

        // Ensure we have arrays of strings, not nested arrays
        $foundStrings = array_map(function ($item) {
            return is_array($item) ? json_encode($item) : (string) $item;
        }, (array) $found);

        $missingStrings = array_map(function ($item) {
            return is_array($item) ? json_encode($item) : (string) $item;
        }, (array) $missing);

        $result = 'loaded DDLs: '.implode(', ', $foundStrings);

        if (! empty($missingStrings)) {
            $result .= '; missing: '.implode(', ', $missingStrings);
        }

        return $result;
    }

    /**
     * Summarize run_sql_query results.
     */
    private static function summarizeRunSqlQuery(array $payload): string
    {
        if (! isset($payload['result_kind'])) {
            return 'executed';
        }

        return match ($payload['result_kind']) {
            'aggregate' => self::summarizeAggregateResult($payload),
            'rows' => self::summarizeRowsResult($payload),
            default => 'executed',
        };
    }

    /**
     * Summarize aggregate query results.
     */
    private static function summarizeAggregateResult(array $payload): string
    {
        $aggregate = $payload['aggregate'] ?? [];
        $label = $aggregate['label'] ?? 'value';
        $value = $aggregate['value'] ?? 'unknown';

        return "aggregate={$label}: {$value}";
    }

    /**
     * Summarize rows query results.
     */
    private static function summarizeRowsResult(array $payload): string
    {
        $rowCount = $payload['row_count'] ?? 0;
        $truncated = $payload['truncated'] ?? false;
        $columns = $payload['columns'] ?? null;

        $result = "rows={$rowCount}, truncated=".($truncated ? 'true' : 'false');

        if ($columns) {
            // Ensure columns is a string, not an array
            $columnsStr = is_array($columns) ? json_encode($columns) : (string) $columns;
            $result .= ", columns={$columnsStr}";
        }

        return $result;
    }

    /**
     * Summarize request_definition results.
     */
    private static function summarizeRequestDefinition(array $payload): string
    {
        // This tool doesn't return meaningful results - it's about requesting input
        return 'pending — user will provide definition';
    }

    /**
     * Summarize parse_definition results.
     */
    private static function summarizeParseDefinition(array $payload): string
    {
        $success = $payload['success'] ?? false;

        return $success ? 'parsed successfully' : 'parsing failed';
    }

    /**
     * Summarize create_definition results.
     */
    private static function summarizeCreateDefinition(array $payload): string
    {
        $created = $payload['created'] ?? false;

        return $created ? 'definition created' : 'definition creation failed';
    }

    /**
     * Summarize unknown tool results.
     */
    private static function summarizeUnknownTool(array $payload): string
    {
        return 'done';
    }
}
