<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Enums\QueryStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Jobs\NotifyToolExecutingJob;
use App\Jobs\PaginateQueryJob;
use App\Mcp\RunSqlQueryTool;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Sql\AggregateDetector;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

/**
 * Test RunSqlQueryTool behavior when non-aggregate queries return zero rows
 */
class RunSqlQueryToolNoRowsTest extends TestCase
{
    private RunSqlQueryTool $tool;

    private DomainCommandBus $mockCommandBus;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();

        // Mock the dependencies
        $this->mockCommandBus = Mockery::mock(DomainCommandBus::class);
        $mockAggregateDetector = Mockery::mock(AggregateDetector::class);

        // For this test, assume SELECT is not an aggregate
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
     * Test that non-aggregate queries with zero rows return no_results and don't dispatch pagination job
     */
    public function test_non_aggregate_query_with_zero_rows_returns_no_results(): void
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

        // Mock the command result for LIMIT 1 query - returns 0 rows
        $selectResult = new SelectResult(columns: [], rows: [], rowCount: 0, truncated: false, limitApplied: 1);

        $commandResponse = ExecuteParameterizedSelectCommandResponse::success($selectResult);

        // Expect the command to be dispatched once for the row count check
        $this->mockCommandBus->shouldReceive('dispatch')
            ->once()
            ->andReturn($commandResponse);

        // Call the tool
        $sql = 'SELECT * FROM users WHERE id = -1';
        $parametersJson = '{}';

        $result = $this->callTool($query->id, $sql, $parametersJson);

        // Assert result_kind is no_results
        $this->assertEquals('no_results', $result['result_kind']);
        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('took_ms', $result);

        // Assert PaginateQueryJob was NOT dispatched
        Bus::assertNotDispatched(PaginateQueryJob::class);

        // Assert NotifyToolExecutingJob was dispatched
        Bus::assertDispatched(NotifyToolExecutingJob::class);
    }
}
