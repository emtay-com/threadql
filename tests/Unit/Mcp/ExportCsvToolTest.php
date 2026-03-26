<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Domain\Export\ExportCsvService;
use App\Domain\Export\ExportResult;
use App\Enums\SettingEnum;
use App\Infrastructure\Slack\SlackMessenger;
use App\Mcp\ExportCsvTool;
use App\Models\Datasource;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Models\ToolCall;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for ExportCsvTool
 */
class ExportCsvToolTest extends TestCase
{
    private ExportCsvTool $tool;

    private ExportCsvService $mockExportService;

    private SlackMessenger $mockSlackMessenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockExportService = Mockery::mock(ExportCsvService::class);
        $this->mockSlackMessenger = Mockery::mock(SlackMessenger::class);
        $this->tool = new ExportCsvTool($this->mockExportService, $this->mockSlackMessenger);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to call the tool and parse the response
     */
    private function callTool(int $queryId, int $sqlCallId, int $rowLimit): array
    {
        $request = new Request([
            'query_id' => $queryId,
            'sql_call_id' => $sqlCallId,
            'row_limit' => $rowLimit,
        ]);

        $response = $this->tool->handle($request);
        $content = $response->content()
            ->toArray();

        return json_decode($content['text'], true);
    }

    /**
     * Test successful CSV export
     */
    public function test_successful_csv_export(): void
    {
        // Create test data with proper relationships
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        // Create a completed tool call with SQL
        $sqlToolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'sql' => 'SELECT id, name FROM users WHERE active = 1',
                'parameters' => [
                    'status' => 'active',
                ],
            ]),
            'response_payload' => json_encode([
                'row_count' => 50,
                'rows' => [[
                    'id' => 1,
                    'name' => 'Alice',
                ]],
            ]),
            'is_completed' => true,
        ]);

        // Mock the export service
        $this->mockExportService->shouldReceive('exportFullQueryToCsv')
            ->once()
            ->andReturn(new ExportResult(success: true, bytes: 1024, rowCount: 50, filePath: '/tmp/test.csv'));

        // Execute the export
        $result = $this->callTool($query->id, $sqlToolCall->id, 100);

        // Assert result structure
        $this->assertTrue($result['ok']);
        $this->assertEquals('csv_export', $result['result_kind']);

        // Verify tool call was created and marked complete
        $exportToolCall = ToolCall::where('tool', 'export_csv')
            ->where('query_id', $query->id)
            ->first();

        $this->assertNotNull($exportToolCall);
        $this->assertTrue($exportToolCall->is_completed);
    }

    /**
     * Test CSV export denied when row count exceeds async limit
     */
    public function test_csv_export_denied_when_async_limit_exceeded(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 1000,
        ]);

        $tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $sqlToolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'sql' => 'SELECT * FROM big_table',
                'parameters' => [],
            ]),
            'response_payload' => json_encode([
                'row_count' => 5000, // Exceeds the 1000 async limit
                'rows' => [],
            ]),
            'is_completed' => true,
        ]);

        $result = $this->callTool($query->id, $sqlToolCall->id, 5000);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_denied', $result['result_kind']);
        $this->assertStringContainsString('too large', $result['message']);

        $exportToolCall = ToolCall::where('tool', 'export_csv')
            ->where('query_id', $query->id)
            ->first();

        $this->assertNotNull($exportToolCall);
        $this->assertTrue($exportToolCall->is_completed);
    }

    /**
     * Test CSV export failure when query not found
     */
    public function test_csv_export_fails_when_query_not_found(): void
    {
        $result = $this->callTool(99999, 1, 100);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertStringContainsString('not found', $result['message']);
    }

    /**
     * Test CSV export failure when tool call not found
     */
    public function test_csv_export_fails_when_tool_call_not_found(): void
    {
        $tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $result = $this->callTool($query->id, 99999, 100);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertStringContainsString('tool call not found', $result['message']);
    }

    /**
     * Test CSV export failure when export service throws exception
     */
    public function test_csv_export_fails_when_export_throws_exception(): void
    {
        $tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        $sqlToolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'sql' => 'SELECT * FROM users',
                'parameters' => [],
            ]),
            'response_payload' => json_encode([
                'row_count' => 50,
                'rows' => [],
            ]),
            'is_completed' => true,
        ]);

        $this->mockExportService->shouldReceive('exportFullQueryToCsv')
            ->once()
            ->andThrow(new \Exception('Database connection failed'));

        $result = $this->callTool($query->id, $sqlToolCall->id, 100);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertStringContainsString('Database connection failed', $result['message']);

        $exportToolCall = ToolCall::where('tool', 'export_csv')
            ->where('query_id', $query->id)
            ->first();

        $this->assertNotNull($exportToolCall);
        $this->assertFalse($exportToolCall->is_completed);
    }
}
