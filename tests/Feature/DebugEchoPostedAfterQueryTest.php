<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Enums\QueryStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Jobs\SendEphemeralSqlDebug;
use App\Mcp\RunSqlQueryTool;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\SlackUser;
use App\Models\SlackUserSetting;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Sql\AggregateDetector;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Request;
use Mockery;
use Tests\TestCase;

final class DebugEchoPostedAfterQueryTest extends TestCase
{
    private RunSqlQueryTool $tool;

    private DomainCommandBus $mockCommandBus;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendEphemeralSqlDebug::class, \App\Jobs\PaginateQueryJob::class]);

        // Mock the dependencies
        $this->mockCommandBus = Mockery::mock(DomainCommandBus::class);
        $mockAggregateDetector = Mockery::mock(AggregateDetector::class);

        // For this test, assume SELECT is an aggregate
        $mockAggregateDetector->shouldReceive('isAggregateQuery')
            ->andReturn(true);

        $this->tool = new RunSqlQueryTool(
            $this->mockCommandBus,
            $mockAggregateDetector,
            app(\App\Infrastructure\Debug\SqlDebugEcho::class)
        );
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
     * Test that debug echo is sent after successful aggregate query when DEBUG is enabled
     */
    public function test_debug_echo_sent_after_successful_aggregate_query_when_debug_enabled(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        SlackUserSetting::factory()->create([
            'slack_user_id' => $slackUser->id,
            'key' => 'debug',
            'value' => 'on',
        ]);
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
            'slack_user_id' => $slackUser->id,
            'status' => QueryStatus::PLANNING->value,
        ]);

        // Mock the command result for aggregate query
        $selectResult = new SelectResult(
            columns: ['count'],
            rows: [[
                'count' => '42',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 0
        );

        $commandResponse = ExecuteParameterizedSelectCommandResponse::success($selectResult);

        // Expect the command to be dispatched
        $this->mockCommandBus->shouldReceive('dispatch')
            ->once()
            ->andReturn($commandResponse);

        // Call the tool
        $sql = 'SELECT COUNT(*) FROM users';
        $parametersJson = '{}';

        $result = $this->callTool($query->id, $sql, $parametersJson);

        // Assert result is successful
        $this->assertEquals('aggregate', $result['result_kind']);
        $this->assertTrue($result['ok']);
        $this->assertEquals('42', $result['value']);

        // Assert debug echo job was dispatched with delay
        Bus::assertDispatched(SendEphemeralSqlDebug::class, function ($job) use ($query, $slackUser) {
            return $job->queryId === $query->id
                && $job->channelId === 'C1234567890'
                && $job->userId === $slackUser->slack_user_id;
        });
    }

    /**
     * Test that debug echo is sent after successful tabular query when DEBUG is enabled
     */
    public function test_debug_echo_sent_after_successful_tabular_query_when_debug_enabled(): void
    {
        // Set up command bus mock for this test
        $this->mockCommandBus = Mockery::mock(DomainCommandBus::class);

        // Override the aggregate detector for this test (not an aggregate)
        $mockAggregateDetector = Mockery::mock(AggregateDetector::class);

        // For tabular queries, return false
        $mockAggregateDetector->shouldReceive('isAggregateQuery')
            ->andReturn(false);

        $this->tool = new RunSqlQueryTool(
            $this->mockCommandBus,
            $mockAggregateDetector,
            app(\App\Infrastructure\Debug\SqlDebugEcho::class)
        );

        // Create test data
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        SlackUserSetting::factory()->create([
            'slack_user_id' => $slackUser->id,
            'key' => 'debug',
            'value' => 'on',
        ]);
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
            'slack_user_id' => $slackUser->id,
            'status' => QueryStatus::PLANNING->value,
        ]);

        // Mock the command results - first for count check (returns 1 row), then for pagination
        $countResult = new SelectResult(
            columns: ['id'],
            rows: [[
                'id' => '1',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 1
        );

        $countCommandResponse = ExecuteParameterizedSelectCommandResponse::success($countResult);

        // Mock the command dispatch to return the count response
        $this->mockCommandBus->shouldReceive('dispatch')
            ->andReturn($countCommandResponse);

        // Call the tool
        $sql = 'SELECT id, name FROM users WHERE active = 1';
        $parametersJson = '{}';

        $result = $this->callTool($query->id, $sql, $parametersJson);

        // Assert result is pending_table (successful tabular query)
        $this->assertEquals('pending_table', $result['result_kind']);
        $this->assertTrue($result['ok']);

        // Assert debug echo job was dispatched with delay
        Bus::assertDispatched(SendEphemeralSqlDebug::class, function ($job) use ($query, $slackUser) {
            return $job->queryId === $query->id
                && $job->channelId === 'C1234567890'
                && $job->userId === $slackUser->slack_user_id;
        });
    }
}
