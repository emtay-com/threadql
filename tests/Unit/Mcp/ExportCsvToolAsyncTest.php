<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Domain\Export\ExportCsvService;
use App\Enums\SettingEnum;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\ExportCsvAndDeliverJob;
use App\Mcp\ExportCsvTool;
use App\Models\Datasource;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Models\ToolCall;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for ExportCsvTool async export functionality
 */
class ExportCsvToolAsyncTest extends TestCase
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

        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

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

    private function createTestData(int $rowCount): array
    {
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
                'row_count' => $rowCount,
                'rows' => [],
            ]),
            'is_completed' => true,
        ]);

        return [$query, $sqlToolCall, $tenant];
    }

    /**
     * Test async export is dispatched when row count exceeds sync limit but within async limit
     */
    public function test_async_export_dispatched_when_row_count_exceeds_sync_limit(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 1000000,
        ]);

        [$query, $sqlToolCall] = $this->createTestData(50000);

        $this->mockSlackMessenger->shouldReceive('replyInThread')
            ->once()
            ->andReturn([
                'ts' => '123',
            ]);

        $result = $this->callTool($query->id, $sqlToolCall->id, 0);

        $this->assertTrue($result['ok']);
        $this->assertEquals('csv_export_async', $result['result_kind']);
        $this->assertEquals('processing', $result['status']);
        $this->assertEquals(50000, $result['row_count']);

        Bus::assertDispatched(ExportCsvAndDeliverJob::class);
    }

    /**
     * Test async export is denied when row count exceeds async limit
     */
    public function test_async_export_denied_when_row_count_exceeds_async_limit(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 1000,
        ]);

        [$query, $sqlToolCall] = $this->createTestData(5000);

        $result = $this->callTool($query->id, $sqlToolCall->id, 0);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_denied', $result['result_kind']);
        $this->assertEquals('limit_exceeded', $result['reason']);
        $this->assertEquals(1000, $result['max_rows_export']);

        Bus::assertNotDispatched(ExportCsvAndDeliverJob::class);
    }

    /**
     * Test async export sends Slack notification
     */
    public function test_async_export_sends_slack_notification(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 1000000,
        ]);

        [$query, $sqlToolCall, $tenant] = $this->createTestData(50000);

        $this->mockSlackMessenger->shouldReceive('replyInThread')
            ->once()
            ->withArgs(function ($receivedTenant, $channelId, $threadTs, $message) use ($tenant) {
                return $receivedTenant->id === $tenant->id
                    && $channelId === 'C1234567890'
                    && $threadTs === '1234567890.123456'
                    && str_contains($message, '50,000 rows');
            })
            ->andReturn([
                'ts' => '123',
            ]);

        $this->callTool($query->id, $sqlToolCall->id, 0);

        $this->assertTrue(true);
    }

    /**
     * Test async export creates tool call record
     */
    public function test_async_export_creates_tool_call_record(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 1000000,
        ]);

        [$query, $sqlToolCall] = $this->createTestData(50000);

        $this->mockSlackMessenger->shouldReceive('replyInThread')
            ->once()
            ->andReturn([
                'ts' => '123',
            ]);

        $this->callTool($query->id, $sqlToolCall->id, 0);

        $exportToolCall = ToolCall::where('tool', 'export_csv')
            ->where('query_id', $query->id)
            ->first();

        $this->assertNotNull($exportToolCall);
        $this->assertFalse($exportToolCall->is_completed); // Not completed until job finishes

        $responsePayload = $exportToolCall->response_payload;
        if (is_string($responsePayload)) {
            $responsePayload = json_decode($responsePayload, true);
        }
        $this->assertEquals('csv_export_async', $responsePayload['result_kind']);
        $this->assertEquals('processing', $responsePayload['status']);
    }
}
