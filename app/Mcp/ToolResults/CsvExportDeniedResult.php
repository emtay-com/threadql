<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

class CsvExportDeniedResult
{
    public static function limitExceeded(int $rowCount, int $max): array
    {
        return [
            'ok' => false,
            'result_kind' => 'csv_export_denied',
            'reason' => 'limit_exceeded',
            'row_count' => $rowCount,
            'max_rows_export' => $max,
            'message' => 'Dataset too large to export.',
        ];
    }
}
