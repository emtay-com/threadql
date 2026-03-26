<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

class CsvExportAsyncAcceptedResult
{
    public static function fromMeta(int $rowCount): array
    {
        return [
            'ok' => true,
            'result_kind' => 'csv_export_async',
            'status' => 'processing',
            'row_count' => $rowCount,
            'message' => 'Large dataset export queued. Results will be posted to this thread when ready.',
        ];
    }
}
