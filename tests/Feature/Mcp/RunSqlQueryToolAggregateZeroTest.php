<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

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
 * Test RunSqlQueryTool behavior for aggregate queries returning zero
 */
class RunSqlQueryToolAggregateZeroTest extends TestCase
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

        // For this test, assume COUNT(*) is an aggregate
        $mockAggregateDetector->shouldReceive('isAggregateQuery')
            ->andReturn(true);

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
     * Test that aggregate queries returning 0 still return aggregate result (no Slack message)
     */
    public function test_aggregate_query_returning_zero_returns_aggregate_result(): void
    {
        // Create test data
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
            'status' => QueryStatus::PLANNING->value,
        ]);

        // Mock the command result for aggregate query returning 0
        $selectResult = new SelectResult(
            columns: ['count'],
            rows: [[
                'count' => 0,
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $commandResponse = ExecuteParameterizedSelectCommandResponse::success($selectResult);

        // Expect the command to be dispatched once
        $this->mockCommandBus->shouldReceive('dispatch')
            ->once()
            ->andReturn($commandResponse);

        // Call the tool with an aggregate query that returns 0
        $sql = 'SELECT COUNT(*) FROM users WHERE id = -1';
        $parametersJson = '{}';

        $result = $this->callTool($query->id, $sql, $parametersJson);

        // Assert result_kind is aggregate with value 0
        $this->assertEquals('aggregate', $result['result_kind']);
        $this->assertEquals(0, $result['value']);
        $this->assertEquals('count', $result['label']);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('took_ms', $result);

        // Assert NotifyToolExecutingJob was dispatched
        Queue::assertPushed(NotifyToolExecutingJob::class);
    }
}
