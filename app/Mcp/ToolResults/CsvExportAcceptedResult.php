<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

class CsvExportAcceptedResult
{
    public static function fromMeta(int $rowCount): array
    {
        return [
            'ok' => true,
            'result_kind' => 'csv_export',
            'status' => 'pending',
            'row_count' => $rowCount,
            'message' => 'CSV export will be delivered here shortly.',
        ];
    }
}
