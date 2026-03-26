<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueryStatus;
use App\Jobs\Support\QueryCacheKeyManager;
use App\Models\Query;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Delayed watchdog that detects queries stuck in a non-terminal status.
 *
 * Dispatched alongside every query job with a configurable delay (default 300s).
 * When it fires it checks the cache key set by QueryLifecycleMiddleware:
 *  - Key present  → job is still running, re-dispatch the watchdog.
 *  - Key absent + non-terminal status → job crashed, mark ERROR and log.
 *  - Terminal status → query finished normally, nothing to do.
 */
class QueryCrashWatchdogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        private readonly int $queryId,
        private readonly int $threadId
    ) {
        $this->afterCommit = true;
    }

    public function handle(QueryCacheKeyManager $keyManager): void
    {
        $query = Query::find($this->queryId);

        if (! $query) {
            return;
        }

        if (in_array($query->status, [QueryStatus::DONE->value, QueryStatus::ERROR->value], true)) {
            return;
        }

        // Cache key still present — job is actively running, check again later
        if ($keyManager->isQueryActive($this->threadId, $this->queryId)) {
            Log::info('QueryCrashWatchdog: query still active, re-scheduling', [
                'query_id' => $this->queryId,
                'thread_id' => $this->threadId,
            ]);

            self::dispatch($this->queryId, $this->threadId)
                ->delay(config('threadql.query_crash_watchdog_delay', 300));

            return;
        }

        // Cache key gone but query not terminal — query has crashed
        $originalStatus = $query->status;
        $query->update([
            'status' => QueryStatus::ERROR,
        ]);

        $failedJob = $this->findFailedJob();

        if ($failedJob) {
            Log::error('QueryCrashWatchdog: query crashed — failed job found', [
                'query_id' => $this->queryId,
                'thread_id' => $this->threadId,
                'original_status' => $originalStatus,
                'failed_job_id' => $failedJob->id,
                'failed_at' => $failedJob->failed_at,
                'exception' => $failedJob->exception,
            ]);
        } else {
            Log::error('QueryCrashWatchdog: query crashed — no failed job record found (possible OOM/worker crash)', [
                'query_id' => $this->queryId,
                'thread_id' => $this->threadId,
                'original_status' => $originalStatus,
                'created_at' => $query->created_at->toIso8601String(),
            ]);
        }
    }

    /**
     * Look up the failed_jobs table for a job that processed this query.
     */
    private function findFailedJob(): ?object
    {
        // Job payloads contain serialized PHP inside JSON, so the queryId
        // appears as: \"queryId\";i:42; (escaped quotes in JSON string)
        return DB::table('failed_jobs')
            ->where('payload', 'like', '%queryId%'.$this->queryId.'%')
            ->orderByDesc('failed_at')
            ->first();
    }
}
