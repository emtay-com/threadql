<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Command\ExecuteParameterizedSelectCommand;
use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Domain\Queries\Anchors\QueryAnchorManager;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Debug\SqlDebugEcho;
use App\Infrastructure\Slack\PaginationControlsBuilder;
use App\Infrastructure\Slack\SlackTableAttachmentBuilder;
use App\Jobs\PaginateQueryJob;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Sql\TotalCountEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PaginateQueryJobTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    private DomainCommandBus|MockInterface $commandBus;

    private PaginationControlsBuilder|MockInterface $paginationBuilder;

    private SlackTableAttachmentBuilder|MockInterface $tableBuilder;

    private QueryAnchorManager|MockInterface $anchorManager;

    private TotalCountEstimator|MockInterface $totalCountEstimator;

    private ?SqlDebugEcho $sqlDebugEcho = null;

    private Tenant $tenant;

    private Thread $thread;

    private Datasource $datasource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->commandBus = Mockery::mock(DomainCommandBus::class);
        $this->paginationBuilder = Mockery::mock(PaginationControlsBuilder::class);
        $this->tableBuilder = Mockery::mock(SlackTableAttachmentBuilder::class);
        $this->anchorManager = Mockery::mock(QueryAnchorManager::class);
        $this->totalCountEstimator = Mockery::mock(TotalCountEstimator::class);

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C12345678',
            'thread_ts' => '1234567890.123456',
            'last_message_ts' => '1234567890.123456',
        ]);
        $this->datasource = Datasource::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    #[Test]
    public function it_has_correct_retry_settings(): void
    {
        $job = new PaginateQueryJob(1, 25, 0);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(30, $job->backoff);
    }

    #[Test]
    public function it_is_queueable(): void
    {
        $job = new PaginateQueryJob(1, 25, 0);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    #[Test]
    public function it_throws_runtime_exception_when_query_not_found(): void
    {
        $job = new PaginateQueryJob(99999, 25, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Query 99999 or required relationships not found');

        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_throws_runtime_exception_when_query_has_no_thread(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        // Delete the thread to simulate orphan
        $this->thread->delete();

        $job = new PaginateQueryJob($query->id, 25, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Query {$query->id} or required relationships not found");

        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_throws_runtime_exception_when_tenant_has_no_datasources(): void
    {
        // Delete the datasource
        $this->datasource->delete();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
        ]);

        $job = new PaginateQueryJob($query->id, 25, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Query {$query->id} or required relationships not found");

        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_uses_total_from_metadata_when_available(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users LIMIT :row_limit OFFSET :offset',
            'result_meta_json' => [
                'total_count' => 100,
                'parameters' => [
                    'offset' => 0,
                ],
            ],
        ]);

        $this->setupSuccessfulExecution($query, 100);

        $job = new PaginateQueryJob($query->id, 25, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );

        // totalCountEstimator should NOT be called when total is in metadata
        $this->totalCountEstimator->shouldNotHaveReceived('estimateTotalCount');
    }

    #[Test]
    public function it_computes_total_when_not_in_metadata(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users LIMIT :row_limit OFFSET :offset',
            'result_meta_json' => null,
        ]);

        $this->totalCountEstimator
            ->shouldReceive('estimateTotalCount')
            ->once()
            ->andReturn(50);

        $this->setupSuccessfulExecution($query, 50);

        $job = new PaginateQueryJob($query->id, 25, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );

        // Verify total was saved to metadata
        $query->refresh();
        $this->assertEquals(50, $query->result_meta_json['total_count']);
    }

    #[Test]
    public function it_throws_runtime_exception_when_sql_execution_fails(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users',
            'result_meta_json' => [
                'total_count' => 100,
            ],
        ]);

        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->andReturn(ExecuteParameterizedSelectCommandResponse::error('Connection failed'));

        $job = new PaginateQueryJob($query->id, 25, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to execute SELECT query: Connection failed');

        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_calls_anchor_manager_to_upsert_table(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT id, name FROM users',
            'result_meta_json' => [
                'total_count' => 10,
            ],
        ]);

        $selectResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [[
                'id' => 1,
                'name' => 'John',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $tablePayload = [
            'blocks' => [[
                'type' => 'table',
            ]],
        ];
        $this->tableBuilder
            ->shouldReceive('build')
            ->with(['id', 'name'], [[
                'id' => 1,
                'name' => 'John',
            ]])
            ->once()
            ->andReturn($tablePayload);

        $this->paginationBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [],
                'text' => 'Total: 10',
            ]);

        // Verify anchor manager is called
        $this->anchorManager
            ->shouldReceive('upsertTableAnchor')
            ->once()
            ->withArgs(function ($q, $payload) use ($query, $tablePayload) {
                return $q->id === $query->id && $payload === $tablePayload;
            });

        // Total is 10, below threshold, so hidePagingAnchor should be called
        $this->anchorManager
            ->shouldReceive('hidePagingAnchor')
            ->once();

        $job = new PaginateQueryJob($query->id, 0, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_calls_anchor_manager_to_upsert_paging_when_total_exceeds_threshold(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT id, name FROM users',
            'result_meta_json' => [
                'total_count' => 100,
            ], // Exceeds default threshold of 25
        ]);

        $selectResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [[
                'id' => 1,
                'name' => 'John',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [[
                    'type' => 'table',
                ]],
            ]);

        $blocksPayload = [
            'blocks' => [[
                'type' => 'section',
            ]],
            'text' => 'Total: 100',
        ];
        $this->paginationBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn($blocksPayload);

        $this->anchorManager
            ->shouldReceive('upsertTableAnchor')
            ->once();

        // Total is 100, above threshold, so upsertPagingAnchor should be called
        $this->anchorManager
            ->shouldReceive('upsertPagingAnchor')
            ->once()
            ->withArgs(function ($q, $payload) use ($query, $blocksPayload) {
                return $q->id === $query->id && $payload === $blocksPayload;
            });

        $job = new PaginateQueryJob($query->id, 0, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_calls_anchor_manager_to_hide_paging_when_total_below_threshold(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT id, name FROM users',
            'result_meta_json' => [
                'total_count' => 10,
            ], // Below threshold
        ]);

        $selectResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [[
                'id' => 1,
                'name' => 'John',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [[
                    'type' => 'table',
                ]],
            ]);

        $this->paginationBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [],
                'text' => 'Total: 10',
            ]);

        $this->anchorManager
            ->shouldReceive('upsertTableAnchor')
            ->once();

        // Total is 10, below threshold, so hidePagingAnchor should be called
        $this->anchorManager
            ->shouldReceive('hidePagingAnchor')
            ->once()
            ->withArgs(fn ($q) => $q->id === $query->id);

        $job = new PaginateQueryJob($query->id, 0, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );
    }

    #[Test]
    public function it_normalizes_offset_to_page_boundary(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users',
            'result_meta_json' => [
                'total_count' => 100,
            ],
        ]);

        $selectResult = new SelectResult(
            columns: ['id'],
            rows: [],
            rowCount: 0,
            truncated: false,
            limitApplied: 25
        );

        // Capture the command to verify offset
        $capturedCommand = null;
        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($command) use (&$capturedCommand) {
                $capturedCommand = $command;

                return $command instanceof ExecuteParameterizedSelectCommand;
            })
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
            ]);
        $this->paginationBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
                'text' => 'Total: 100',
            ]);
        $this->anchorManager->shouldReceive('upsertTableAnchor');
        $this->anchorManager->shouldReceive('upsertPagingAnchor');

        // Request offset 27 which should normalize to 25 (page boundary)
        $job = new PaginateQueryJob($query->id, 27, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );

        $this->assertEquals(25, $capturedCommand->parameters['offset']);
    }

    #[Test]
    public function it_clamps_offset_to_valid_range(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users',
            'result_meta_json' => [
                'total_count' => 50,
            ],
        ]);

        $selectResult = new SelectResult(
            columns: ['id'],
            rows: [],
            rowCount: 0,
            truncated: false,
            limitApplied: 25
        );

        $capturedCommand = null;
        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($command) use (&$capturedCommand) {
                $capturedCommand = $command;

                return true;
            })
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
            ]);
        $this->paginationBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
                'text' => 'Total: 50',
            ]);
        $this->anchorManager->shouldReceive('upsertTableAnchor');
        $this->anchorManager->shouldReceive('upsertPagingAnchor');

        // Request offset 1000 which exceeds total, should clamp to last page (25)
        $job = new PaginateQueryJob($query->id, 1000, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );

        $this->assertEquals(25, $capturedCommand->parameters['offset']);
    }

    #[Test]
    public function it_handles_negative_offset_gracefully(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => 'done',
            'sql_text' => 'SELECT * FROM users',
            'result_meta_json' => [
                'total_count' => 50,
            ],
        ]);

        $selectResult = new SelectResult(
            columns: ['id'],
            rows: [],
            rowCount: 0,
            truncated: false,
            limitApplied: 25
        );

        $capturedCommand = null;
        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($command) use (&$capturedCommand) {
                $capturedCommand = $command;

                return true;
            })
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
            ]);
        $this->paginationBuilder->shouldReceive('build')
            ->andReturn([
                'blocks' => [],
                'text' => 'Total: 50',
            ]);
        $this->anchorManager->shouldReceive('upsertTableAnchor');
        $this->anchorManager->shouldReceive('upsertPagingAnchor');

        // Negative offset should clamp to 0
        $job = new PaginateQueryJob($query->id, -50, 0);
        $job->handle(
            $this->commandBus,
            $this->paginationBuilder,
            $this->tableBuilder,
            $this->anchorManager,
            $this->totalCountEstimator,
            $this->sqlDebugEcho
        );

        $this->assertEquals(0, $capturedCommand->parameters['offset']);
    }

    /**
     * Helper to setup mocks for successful execution
     */
    private function setupSuccessfulExecution(Query $query, int $total): void
    {
        $selectResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [[
                'id' => 1,
                'name' => 'John',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->commandBus
            ->shouldReceive('dispatch')
            ->once()
            ->andReturn(ExecuteParameterizedSelectCommandResponse::success($selectResult));

        $this->tableBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [[
                    'type' => 'table',
                ]],
            ]);

        $this->paginationBuilder
            ->shouldReceive('build')
            ->once()
            ->andReturn([
                'blocks' => [],
                'text' => "Total: {$total}",
            ]);

        $this->anchorManager
            ->shouldReceive('upsertTableAnchor')
            ->once();

        if ($total > 25) {
            $this->anchorManager
                ->shouldReceive('upsertPagingAnchor')
                ->once();
        } else {
            $this->anchorManager
                ->shouldReceive('hidePagingAnchor')
                ->once();
        }
    }
}
