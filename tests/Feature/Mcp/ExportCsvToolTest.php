<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Export\ExportCsvService;
use App\Domain\Export\ExportResult;
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
use Laravel\Mcp\Server\Tool;
use Mockery;
use Tests\TestCase;

/**
 * Test the export_csv MCP tool functionality
 */
class ExportCsvToolTest extends TestCase
{
    private ExportCsvTool $exportCsvTool;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the ExportCsvService to avoid actual database operations
        $this->exportCsvServiceMock = Mockery::mock(ExportCsvService::class);
        $this->app->instance(ExportCsvService::class, $this->exportCsvServiceMock);

        // Mock SlackMessenger
        $this->slackMessengerMock = Mockery::mock(SlackMessenger::class);
        $this->app->instance(SlackMessenger::class, $this->slackMessengerMock);

        $this->exportCsvTool = app(ExportCsvTool::class);

        // Fake job dispatching to prevent actual job execution in tests
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test export_csv tool extends Laravel\Mcp\Server\Tool
     */
    public function test_export_csv_tool_extends_base_tool(): void
    {
        $this->assertInstanceOf(Tool::class, $this->exportCsvTool);
    }

    /**
     * Test export_csv tool has correct name
     */
    public function test_export_csv_tool_has_correct_name(): void
    {
        $this->assertEquals('export_csv', $this->exportCsvTool->name());
    }

    /**
     * Test export_csv returns pending response for valid request
     */
    public function test_export_csv_returns_pending_for_valid_request(): void
    {
        // Create test data
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
        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'sql' => 'SELECT * FROM users LIMIT 10',
                'parameters' => [],
            ]),
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'pending_table',
                'row_count' => 50,  // Less than the default limit of 10000
                'message' => 'Resultset will be posted in the Slack thread.',
            ]),
            'is_completed' => true,
        ]);

        // Mock the exportFullQueryToCsv method (should be called for accepted exports)
        $this->exportCsvServiceMock->shouldReceive('exportFullQueryToCsv')
            ->once()
            ->andReturn(new ExportResult(true, 1024, 50, '/tmp/test.csv'));

        // Create a mock request
        $request = new Request([
            'query_id' => $query->id,
            'sql_call_id' => $toolCall->id,
            'row_limit' => 100,
        ]);

        // Call the tool
        $response = $this->exportCsvTool->handle($request);

        // Get the response content
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        // Verify response structure
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('result_kind', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('row_count', $result);
        $this->assertArrayHasKey('message', $result);

        // Verify values
        $this->assertTrue($result['ok']);
        $this->assertEquals('csv_export', $result['result_kind']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals(50, $result['row_count']); // From the tool call response
        $this->assertEquals('CSV export will be delivered here shortly.', $result['message']);
    }

    /**
     * Test export_csv dispatches async job for large datasets
     */
    public function test_export_csv_dispatches_async_job_for_large_datasets(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);
        config([
            'export.max_rows_async_export' => 2000000,
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
        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'sql' => 'SELECT * FROM big_table',
                'parameters' => [],
            ]),
            'response_payload' => json_encode([
                'row_count' => 50000,
            ]),
            'is_completed' => true,
        ]);

        $this->slackMessengerMock->shouldReceive('replyInThread')
            ->once()
            ->andReturn([
                'ts' => '123',
            ]);

        $request = new Request([
            'query_id' => $query->id,
            'sql_call_id' => $toolCall->id,
            'row_limit' => 0,
        ]);

        $response = $this->exportCsvTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertTrue($result['ok']);
        $this->assertEquals('csv_export_async', $result['result_kind']);
        $this->assertEquals('processing', $result['status']);

        Bus::assertDispatched(ExportCsvAndDeliverJob::class);
    }

    /**
     * Test export_csv returns error for invalid query_id
     */
    public function test_export_csv_returns_error_for_invalid_query_id(): void
    {
        $request = new Request([
            'query_id' => 99999,
            'sql_call_id' => 1,
            'row_limit' => 100,
        ]);

        $response = $this->exportCsvTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('result_kind', $result);
        $this->assertArrayHasKey('message', $result);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertEquals('Query not found', $result['message']);
    }

    /**
     * Test export_csv returns error for invalid sql_call_id
     */
    public function test_export_csv_returns_error_for_invalid_sql_call_id(): void
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

        $request = new Request([
            'query_id' => $query->id,
            'sql_call_id' => 99999,
            'row_limit' => 100,
        ]);

        $response = $this->exportCsvTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertEquals('Invalid sql_call_id: tool call not found or incomplete', $result['message']);
    }

    /**
     * Test export_csv returns error for wrong tool type
     */
    public function test_export_csv_returns_error_for_wrong_tool_type(): void
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
        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tool' => 'fetch_table_ddls', // Wrong tool type
            'request_payload' => json_encode([
                'table_names' => ['users'],
            ]),
            'response_payload' => json_encode([
                'ok' => true,
            ]),
        ]);

        $request = new Request([
            'query_id' => $query->id,
            'sql_call_id' => $toolCall->id,
            'row_limit' => 100,
        ]);

        $response = $this->exportCsvTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertEquals('Invalid sql_call_id: tool call not found or incomplete', $result['message']);
    }
}
