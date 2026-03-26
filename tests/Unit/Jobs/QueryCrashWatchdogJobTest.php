<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\QueryStatus;
use App\Jobs\QueryCrashWatchdogJob;
use App\Jobs\Support\QueryCacheKeyManager;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueryCrashWatchdogJobTest extends TestCase
{
    private Tenant $tenant;

    private Thread $thread;

    private QueryCacheKeyManager $keyManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->keyManager = new QueryCacheKeyManager(Cache::store());
    }

    public function test_it_does_nothing_when_query_is_done(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::DONE->value,
        ]);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('info')->never();

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        $this->assertEquals(QueryStatus::DONE->value, $query->fresh()->status);
    }

    public function test_it_does_nothing_when_query_is_error(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::ERROR->value,
        ]);

        Log::shouldReceive('error')->never();
        Log::shouldReceive('info')->never();

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        $this->assertEquals(QueryStatus::ERROR->value, $query->fresh()->status);
    }

    public function test_it_does_nothing_when_query_not_found(): void
    {
        Log::shouldReceive('error')->never();
        Log::shouldReceive('info')->never();

        $job = new QueryCrashWatchdogJob(999999, $this->thread->id);
        $job->handle($this->keyManager);
    }

    public function test_it_redispatches_when_cache_key_active(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::RECEIVED->value,
        ]);

        // Simulate an active query via cache key
        $this->keyManager->markActive($this->thread->id, $query->id);

        Queue::fake();
        Log::shouldReceive('info')->once();

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        // Query should NOT be marked as error
        $this->assertEquals(QueryStatus::RECEIVED->value, $query->fresh()->status);

        // Watchdog should be re-dispatched
        Queue::assertPushed(QueryCrashWatchdogJob::class);
    }

    public function test_it_marks_query_as_error_when_cache_key_absent(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::RECEIVED->value,
        ]);

        // No cache key set — simulates a crashed job

        Log::shouldReceive('error')->once()->withArgs(function (string $message) {
            return str_contains($message, 'no failed job record found');
        });

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        $this->assertEquals(QueryStatus::ERROR->value, $query->fresh()->status);
    }

    public function test_it_logs_failed_job_exception_when_found(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::RECEIVED->value,
        ]);

        // Insert a matching failed_jobs entry
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'long_queue',
            'payload' => json_encode([
                'data' => [
                    'command' => serialize((object) [
                        'queryId' => $query->id,
                    ]),
                ],
            ]),
            'exception' => 'RuntimeException: LLM provider timed out in /app/Services/Query/QueryExecutionService.php:42',
            'failed_at' => now(),
        ]);

        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
            return str_contains($message, 'failed job found')
                && str_contains($context['exception'], 'LLM provider timed out');
        });

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        $this->assertEquals(QueryStatus::ERROR->value, $query->fresh()->status);
    }

    public function test_it_handles_input_requested_status_as_in_flight(): void
    {
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::INPUT_REQUESTED->value,
        ]);

        Log::shouldReceive('error')->once();

        $job = new QueryCrashWatchdogJob($query->id, $this->thread->id);
        $job->handle($this->keyManager);

        $this->assertEquals(QueryStatus::ERROR->value, $query->fresh()->status);
    }
}
