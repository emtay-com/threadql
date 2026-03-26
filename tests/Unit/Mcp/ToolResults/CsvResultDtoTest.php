<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\ToolResults;

use App\Mcp\ToolResults\CsvExportAcceptedResult;
use App\Mcp\ToolResults\CsvExportDeniedResult;
use App\Mcp\ToolResults\CsvExportFailedResult;
use PHPUnit\Framework\TestCase;

/**
 * Test the CSV export result DTOs
 */
class CsvResultDtoTest extends TestCase
{
    public function test_csv_export_accepted_result_structure(): void
    {
        $result = CsvExportAcceptedResult::fromMeta(150);

        $expected = [
            'ok' => true,
            'result_kind' => 'csv_export',
            'status' => 'pending',
            'row_count' => 150,
            'message' => 'CSV export will be delivered here shortly.',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_csv_export_denied_result_structure(): void
    {
        $result = CsvExportDeniedResult::limitExceeded(25000, 10000);

        $expected = [
            'ok' => false,
            'result_kind' => 'csv_export_denied',
            'reason' => 'limit_exceeded',
            'row_count' => 25000,
            'max_rows_export' => 10000,
            'message' => 'Dataset too large to export.',
        ];

        $this->assertEquals($expected, $result);
    }

    public function test_csv_export_failed_result_structure(): void
    {
        $result = CsvExportFailedResult::unexpected('Database connection failed');

        $expected = [
            'ok' => false,
            'result_kind' => 'csv_export_failed',
            'reason' => 'unexpected_error',
            'message' => 'Database connection failed',
        ];

        $this->assertEquals($expected, $result);
    }
}
