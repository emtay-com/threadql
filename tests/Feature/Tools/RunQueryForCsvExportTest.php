<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Domain\Export\ExportCsvService;
use App\Infrastructure\Slack\SlackMessenger;
use App\Mcp\RunQueryForCsvExportTool;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Mockery;
use Tests\TestCase;

/**
 * Test the run_query_for_csv_export MCP tool functionality
 */
class RunQueryForCsvExportTest extends TestCase
{
    private RunQueryForCsvExportTool $runQueryForCsvExportTool;

    private ExportCsvService $mockExportCsvService;

    private SlackMessenger $mockSlackMessenger;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the dependencies to avoid actual database operations
        $this->mockExportCsvService = Mockery::mock(ExportCsvService::class);
        $this->mockSlackMessenger = Mockery::mock(SlackMessenger::class);

        // Mock the row count to return a value below the limit (10000)
        $this->mockExportCsvService->shouldReceive('getRowCount')
            ->andReturn(50); // Below the default limit

        // Mock the export operation (should succeed)
        $this->mockExportCsvService->shouldReceive('exportFullQueryToCsv')
            ->andReturn(new \App\Domain\Export\ExportResult(true, 1024, 50, '/tmp/test.csv'));

        $this->runQueryForCsvExportTool = new RunQueryForCsvExportTool(
            $this->mockExportCsvService,
            $this->mockSlackMessenger
        );

        // Fake job dispatching to prevent actual job execution in tests
        Bus::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test run_query_for_csv_export tool extends Laravel\Mcp\Server\Tool
     */
    public function test_run_query_for_csv_export_tool_extends_base_tool(): void
    {
        $this->assertInstanceOf(Tool::class, $this->runQueryForCsvExportTool);
    }

    /**
     * Test run_query_for_csv_export tool has correct name
     */
    public function test_run_query_for_csv_export_tool_has_correct_name(): void
    {
        $this->assertEquals('run_query_for_csv_export', $this->runQueryForCsvExportTool->name());
    }

    /**
     * Test run_query_for_csv_export returns pending response for valid request
     */
    public function test_run_query_for_csv_export_returns_pending_for_valid_request(): void
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

        // Create request
        $request = new Request([
            'query_id' => $query->id,
            'sql' => 'SELECT id, name FROM users WHERE status = :status LIMIT :row_limit',
            'parametersJson' => '{"status": "active"}',
            'row_limit' => 500,
        ]);

        // Call the tool
        $response = $this->runQueryForCsvExportTool->handle($request);
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
        $this->assertEquals(50, $result['row_count']); // From our mock
        $this->assertEquals('CSV export will be delivered here shortly.', $result['message']);
    }

    /**
     * Test run_query_for_csv_export returns error for invalid query_id
     */
    public function test_run_query_for_csv_export_returns_error_for_invalid_query_id(): void
    {
        $request = new Request([
            'query_id' => 99999,
            'sql' => 'SELECT * FROM users',
            'parametersJson' => '{}',
            'row_limit' => 1000,
        ]);

        $response = $this->runQueryForCsvExportTool->handle($request);
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
     * Test run_query_for_csv_export returns error for invalid SQL
     */
    public function test_run_query_for_csv_export_returns_error_for_invalid_sql(): void
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

        $request = new Request([
            'query_id' => $query->id,
            'sql' => '', // Empty SQL
            'parametersJson' => '{}',
            'row_limit' => 1000,
        ]);

        $response = $this->runQueryForCsvExportTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('result_kind', $result);
        $this->assertArrayHasKey('message', $result);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertEquals('Invalid SQL provided', $result['message']);
    }

    /**
     * Test run_query_for_csv_export returns error for non-SELECT SQL
     */
    public function test_run_query_for_csv_export_returns_error_for_non_select_sql(): void
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

        $request = new Request([
            'query_id' => $query->id,
            'sql' => 'UPDATE users SET status = "inactive"', // Non-SELECT
            'parametersJson' => '{}',
            'row_limit' => 1000,
        ]);

        $response = $this->runQueryForCsvExportTool->handle($request);
        $content = $response->content()
            ->toArray();
        $result = json_decode($content['text'], true);

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('result_kind', $result);
        $this->assertArrayHasKey('message', $result);

        $this->assertFalse($result['ok']);
        $this->assertEquals('csv_export_failed', $result['result_kind']);
        $this->assertEquals('Only SELECT statements are allowed', $result['message']);
    }

    /**
     * Test run_query_for_csv_export creates tool call record
     */
    public function test_run_query_for_csv_export_creates_tool_call_record(): void
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

        $request = new Request([
            'query_id' => $query->id,
            'sql' => 'SELECT * FROM users',
            'parametersJson' => '{"status": "active"}',
            'row_limit' => 1000,
        ]);

        // Call the tool
        $this->runQueryForCsvExportTool->handle($request);

        // Assert that a tool call record was created
        $this->assertDatabaseHas('tool_calls', [
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'run_query_for_csv_export',
        ]);

        // Verify the request payload contains the expected data
        $toolCall = \App\Models\ToolCall::where('query_id', $query->id)
            ->where('tool', 'run_query_for_csv_export')
            ->first();

        $this->assertNotNull($toolCall);
        $requestPayload = json_decode($toolCall->request_payload, true);

        $this->assertEquals('SELECT * FROM users', $requestPayload['sql']);
        $this->assertEquals([
            'status' => 'active',
        ], $requestPayload['parameters']);
        $this->assertEquals(1000, $requestPayload['row_limit']);
    }
}
