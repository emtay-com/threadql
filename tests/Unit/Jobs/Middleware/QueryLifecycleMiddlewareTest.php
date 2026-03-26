<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Middleware;

use App\Enums\QueryStatus;
use App\Exceptions\DatasourceNotSetException;
use App\Jobs\Contracts\QueryJobContract;
use App\Jobs\Middleware\QueryLifecycleMiddleware;
use App\Jobs\Support\QueryCacheKeyManager;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class QueryLifecycleMiddlewareTest extends TestCase
{
    private QueryCacheKeyManager $keyManager;

    private QueryLifecycleMiddleware $middleware;

    private Tenant $tenant;

    private Thread $thread;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyManager = new QueryCacheKeyManager(Cache::store());
        $this->middleware = new QueryLifecycleMiddleware($this->keyManager);
        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_it_sets_cache_keys_before_job_runs(): void
    {
        $query = $this->createQuery();
        $job = $this->makeJob($this->thread->id, $query->id);

        $keysSetDuringExecution = false;

        Log::shouldReceive('info')->atLeast()->times(1);

        $this->middleware->handle($job, function () use (&$keysSetDuringExecution) {
            $keysSetDuringExecution = Cache::has('query-active:'.$this->thread->id.':'.$this->lastQueryId);

            return null;
        });

        $this->assertTrue($keysSetDuringExecution);
    }

    public function test_it_removes_cache_keys_after_successful_job(): void
    {
        $query = $this->createQuery();
        $job = $this->makeJob($this->thread->id, $query->id);

        Log::shouldReceive('info')->atLeast()->times(1);

        $this->middleware->handle($job, function () {
            return null;
        });

        $this->assertFalse($this->keyManager->isQueryActive($this->thread->id, $query->id));
        $this->assertFalse($this->keyManager->hasActiveQueryInThread($this->thread->id));
    }

    public function test_it_removes_cache_keys_after_recoverable_exception(): void
    {
        $query = $this->createQuery();
        $job = $this->makeJob($this->thread->id, $query->id);

        Log::shouldReceive('info')->atLeast()->times(1);

        $this->expectException(RuntimeException::class);

        try {
            $this->middleware->handle($job, function () {
                throw new RuntimeException('Temporary failure');
            });
        } finally {
            $this->assertFalse($this->keyManager->isQueryActive($this->thread->id, $query->id));
            $this->assertFalse($this->keyManager->hasActiveQueryInThread($this->thread->id));
        }
    }

    public function test_it_marks_query_error_on_unrecoverable_exception(): void
    {
        $query = $this->createQuery(QueryStatus::RECEIVED);
        $job = $this->makeJob($this->thread->id, $query->id);

        Log::shouldReceive('info')->atLeast()->times(1);
        Log::shouldReceive('error')->once();

        $this->expectException(DatasourceNotSetException::class);

        try {
            $this->middleware->handle($job, function () {
                throw new DatasourceNotSetException(1);
            });
        } finally {
            $freshQuery = $query->fresh();
            $this->assertEquals(QueryStatus::ERROR->value, $freshQuery->status);
            $this->assertStringContainsString('DatasourceNotSetException', $freshQuery->outcome);
            $this->assertFalse($this->keyManager->isQueryActive($this->thread->id, $query->id));
        }
    }

    public function test_it_does_not_overwrite_terminal_status_on_unrecoverable(): void
    {
        $query = $this->createQuery(QueryStatus::DONE);
        $job = $this->makeJob($this->thread->id, $query->id);

        Log::shouldReceive('info')->atLeast()->times(1);
        Log::shouldReceive('error')->once();

        $this->expectException(DatasourceNotSetException::class);

        try {
            $this->middleware->handle($job, function () {
                throw new DatasourceNotSetException(1);
            });
        } finally {
            // Should stay DONE, not be overwritten to ERROR
            $this->assertEquals(QueryStatus::DONE->value, $query->fresh()->status);
        }
    }

    public function test_it_passes_through_non_query_jobs(): void
    {
        $nonQueryJob = new \stdClass;
        $called = false;

        $this->middleware->handle($nonQueryJob, function () use (&$called) {
            $called = true;

            return null;
        });

        $this->assertTrue($called);
    }

    private int $lastQueryId;

    private function createQuery(QueryStatus $status = QueryStatus::RECEIVED): Query
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => $status->value,
        ]);

        $this->lastQueryId = $query->id;

        return $query;
    }

    private function makeJob(int $threadId, int $queryId): QueryJobContract
    {
        return new class($threadId, $queryId) implements QueryJobContract
        {
            public function __construct(
                private readonly int $threadId,
                private readonly int $queryId
            ) {
            }

            public function getQueryId(): int
            {
                return $this->queryId;
            }

            public function getThreadId(): int
            {
                return $this->threadId;
            }
        };
    }
}
