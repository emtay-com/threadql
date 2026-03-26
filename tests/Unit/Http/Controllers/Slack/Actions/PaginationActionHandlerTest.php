<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Slack\Actions;

use App\Domain\Queries\Anchors\AnchorType;
use App\Http\Controllers\Slack\Actions\PaginationActionHandler;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\PaginateQueryJob;
use App\Models\Query;
use App\Models\QueryAnchor;
use App\Models\Tenant;
use App\Models\Thread;
use App\Repositories\QueryAnchorService;
use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class PaginationActionHandlerTest extends TestCase
{
    private SlackMessenger $slackMessenger;

    private QueryAnchorService $anchorService;

    private PaginationActionHandler $handler;

    private Tenant $tenant;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slackMessenger = $this->createMock(SlackMessenger::class);
        $this->anchorService = $this->createMock(QueryAnchorService::class);
        $this->handler = new PaginationActionHandler($this->slackMessenger, $this->anchorService);

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_handle_dispatches_pagination_job_for_valid_query(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'parameters' => [
                    'offset' => 25,
                ],
            ],
        ]);

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->with($query->id, AnchorType::PAGINATION_BLOCKS)
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '50');

        $this->assertEquals(204, $response->getStatusCode());

        Bus::assertDispatched(PaginateQueryJob::class, function ($job) use ($query) {
            return $job->queryId === $query->id
                && $job->requestedOffset === 50
                && $job->currentOffset === 25;
        });
    }

    public function test_handle_returns_no_content_when_query_not_found(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->once();
        Log::shouldReceive('warning')->once()->with('Pagination button clicked for invalid query', [
            'query_id' => 999,
        ]);

        $response = $this->handler->handle(999, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_handle_returns_no_content_when_query_has_no_thread(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->once();

        // Create query then delete its thread to simulate orphan
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        // Delete the thread to create an orphaned query
        $this->thread->delete();

        Log::shouldReceive('warning')->once()->with('Pagination button clicked for invalid query', [
            'query_id' => $query->id,
        ]);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_handle_returns_no_content_when_query_has_no_tenant(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->once();

        // Create query then delete its tenant to simulate orphan
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        // Delete the tenant to create an orphaned query
        $this->tenant->delete();

        Log::shouldReceive('warning')->once()->with('Pagination button clicked for invalid query', [
            'query_id' => $query->id,
        ]);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_handle_returns_no_content_when_query_has_invalid_status(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->once();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'executing',
        ]);

        Log::shouldReceive('warning')->once()->with('Pagination button clicked for query not in valid state', [
            'query_id' => $query->id,
            'status' => 'executing',
        ]);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_handle_accepts_input_requested_status(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'input_requested',
        ]);

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertDispatched(PaginateQueryJob::class);
    }

    public function test_handle_uses_zero_offset_when_no_metadata(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'result_meta_json' => null,
        ]);

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());

        Bus::assertDispatched(PaginateQueryJob::class, function ($job) {
            return $job->currentOffset === 0;
        });
    }

    public function test_handle_uses_zero_offset_when_offset_not_in_metadata(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'parameters' => [
                    'row_limit' => 25,
                ],
            ],
        ]);

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());

        Bus::assertDispatched(PaginateQueryJob::class, function ($job) {
            return $job->currentOffset === 0;
        });
    }

    public function test_handle_updates_working_message_when_anchor_found(): void
    {
        Bus::fake();
        Log::shouldReceive('info')->twice();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        $anchor = new QueryAnchor([
            'query_id' => $query->id,
            'anchor_type' => AnchorType::PAGINATION_BLOCKS->value,
            'message_ts' => '1234567890.123456',
            'blocks_json' => [],
        ]);
        $anchor->id = 1;

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->with($query->id, AnchorType::PAGINATION_BLOCKS)
            ->willReturn($anchor);

        $expectedBlocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '_Working on it…_',
                ],
            ],
        ];

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessageBlocks')
            ->with(
                $this->callback(fn ($tenant) => $tenant->id === $this->tenant->id),
                $this->thread->channel_id,
                '1234567890.123456',
                'Working on it…',
                $expectedBlocks
            );

        $this->anchorService
            ->expects($this->once())
            ->method('updateBlocks')
            ->with($anchor, $expectedBlocks);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_handle_logs_warning_when_anchor_not_found(): void
    {
        Bus::fake();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once()->with('No pagination controls anchor found for working message', [
            'query_id' => $query->id,
        ]);

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '25');

        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertDispatched(PaginateQueryJob::class);
    }

    public function test_handle_catches_exception_when_updating_working_message_fails(): void
    {
        Bus::fake();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        Log::shouldReceive('info')->twice();
        Log::shouldReceive('warning')->once()->with('Failed to show working on it message', [
            'query_id' => $query->id,
            'error' => 'Slack API error',
        ]);

        $anchor = new QueryAnchor([
            'query_id' => $query->id,
            'anchor_type' => AnchorType::PAGINATION_BLOCKS->value,
            'message_ts' => '1234567890.123456',
            'blocks_json' => [],
        ]);
        $anchor->id = 1;

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn($anchor);

        $exception = new Exception('Slack API error');

        $this->slackMessenger
            ->expects($this->once())
            ->method('updateMessageBlocks')
            ->willThrowException($exception);

        $response = $this->handler->handle($query->id, '25');

        // Should still succeed and dispatch job despite error updating message
        $this->assertEquals(204, $response->getStatusCode());
        Bus::assertDispatched(PaginateQueryJob::class);
    }

    public function test_handle_logs_pagination_request_info(): void
    {
        Bus::fake();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'parameters' => [
                    'offset' => 50,
                ],
            ],
        ]);

        Log::shouldReceive('info')->once()->with('Handling pagination button click ', [
            'query_id' => $query->id,
            'value' => '75',
        ]);

        Log::shouldReceive('info')->once()->with('Dispatching pagination job', [
            'query_id' => $query->id,
            'requested_offset' => 75,
            'current_offset' => 50,
        ]);

        Log::shouldReceive('warning')->once();

        $this->anchorService
            ->expects($this->once())
            ->method('getByQueryAndType')
            ->willReturn(null);

        $response = $this->handler->handle($query->id, '75');

        $this->assertEquals(204, $response->getStatusCode());
    }
}
