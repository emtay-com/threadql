<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Enums\QueryStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Jobs\NotifyToolExecutingJob;
use App\Mcp\RunSqlQueryTool;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Sql\AggregateDetector;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for RunSqlQueryTool
 */
class RunSqlQueryToolTest extends TestCase
{
    private RunSqlQueryTool $tool;

    private DomainCommandBus $mockCommandBus;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Mock the dependencies
        $this->mockCommandBus = Mockery::mock(DomainCommandBus::class);
        $mockAggregateDetector = Mockery::mock(AggregateDetector::class);

        // For this test, assume SELECT 1 is not an aggregate
        $mockAggregateDetector->shouldReceive('isAggregateQuery')
            ->andReturn(false);

        $this->tool = new RunSqlQueryTool($this->mockCommandBus, $mockAggregateDetector);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Helper to call the tool and parse the response
     */
    private function callTool(int $queryId, string $sql, string $parametersJson): array
    {
        $request = new Request([
            'query_id' => $queryId,
            'sql' => $sql,
            'parametersJson' => $parametersJson,
        ]);

        $response = $this->tool->handle($request);
        $content = $response->content()
            ->toArray();

        return json_decode($content['text'], true);
    }

    /**
     * Test successful SQL query execution
     */
    public function test_successful_sql_execution(): void
    {
        // Create test data with proper relationships
        $tenant = Tenant::factory()->withoutLlmProvider()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://root:root@127.0.0.1:3306/threadql_test',
        ]);
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::PLANNING->value,
        ]);

        // Mock the command result
        $selectResult = new SelectResult(
            columns: ['one'],
            rows: [[
                'one' => 1,
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 200
        );

        $commandResponse = ExecuteParameterizedSelectCommandResponse::success($selectResult);

        // Mock the command dispatch for row count check (LIMIT 1)
        $this->mockCommandBus->shouldReceive('dispatch')
            ->once()
            ->andReturn($commandResponse);

        // Execute the tool method - should dispatch PaginateQueryJob and return pending_table
        $result = $this->callTool($query->id, 'SELECT 1 AS one LIMIT :row_limit', '{}');

        $this->assertTrue($result['ok']);
        $this->assertEquals('pending_table', $result['result_kind']);
        $this->assertEquals('Resultset will be posted in the Slack thread.', $result['message']);
        $this->assertIsInt($result['took_ms']);

        // Verify notification job was dispatched
        Queue::assertPushed(NotifyToolExecutingJob::class);
    }

    /**
     * Test successful SQL query execution with parameters
     */
    public function test_successful_sql_execution_with_parameters(): void
    {
        // Create test data with proper relationships
        $tenant = Tenant::factory()->withoutLlmProvider()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://root:root@127.0.0.1:3306/threadql_test',
        ]);
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::PLANNING->value,
        ]);

        // Mock the command result
        $selectResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [[
                'id' => 1,
                'name' => 'John',
            ], [
                'id' => 2,
                'name' => 'Jane',
            ]],
            rowCount: 2,
            truncated: false,
            limitApplied: 10
        );

        $commandResponse = ExecuteParameterizedSelectCommandResponse::success($selectResult);

        $parameters = [
            ':user_id' => 1,
            ':status' => 'active',
            ':row_limit' => 10,
        ];

        // Mock the command dispatch for row count check (LIMIT 1)
        $this->mockCommandBus->shouldReceive('dispatch')
            ->once()
            ->andReturn($commandResponse);

        // Execute the service method with parameters
        $result = $this->callTool(
            $query->id,
            'SELECT id, name FROM users WHERE id = :user_id AND status = :status LIMIT :row_limit',
            json_encode($parameters)
        );

        // Verify the result - should be pending_table since this is not an aggregate
        $this->assertTrue($result['ok']);
        $this->assertEquals('pending_table', $result['result_kind']);
        $this->assertEquals('Resultset will be posted in the Slack thread.', $result['message']);
        $this->assertIsInt($result['took_ms']);

        // Verify notification job was dispatched
        Queue::assertPushed(NotifyToolExecutingJob::class);
    }

    /**
     * Test error handling for non-associative parameters array
     */
    public function test_non_associative_parameters_array(): void
    {
        // Test with indexed array instead of associative - should fail before command dispatch
        $result = $this->callTool(404, 'SELECT 1 AS one', json_encode(['value1', 'value2']));

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Parameters must be an object', $result['error']);
    }

    /**
     * Test error handling for invalid query_id
     */
    public function test_invalid_query_id(): void
    {
        // No command bus mocking needed - should fail before dispatch
        $result = $this->callTool(99999, 'SELECT 1 AS one LIMIT :row_limit', '{}');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Query not found', $result['error']);
    }

    /**
     * Test error handling for non-SELECT statements
     */
    public function test_non_select_statement_rejected(): void
    {
        // Should fail before command dispatch
        $result = $this->callTool(789, 'UPDATE users SET name = "test"', '{}');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Only SELECT statements are allowed', $result['error']);
    }

    /**
     * Test error handling for invalid SQL
     */
    public function test_invalid_sql(): void
    {
        // Should fail before command dispatch
        $result = $this->callTool(101, '', '{}');

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid SQL provided', $result['error']);
    }
}
