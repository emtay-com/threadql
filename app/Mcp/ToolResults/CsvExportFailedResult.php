<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

class CsvExportFailedResult
{
    public static function unexpected(string $err): array
    {
        return [
            'ok' => false,
            'result_kind' => 'csv_export_failed',
            'reason' => 'unexpected_error',
            'message' => $err,
        ];
    }
}
