<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Enums\QueryStatus;
use App\Exceptions\UnrecoverableJobException;
use App\Jobs\Contracts\QueryJobContract;
use App\Jobs\Support\QueryCacheKeyManager;
use App\Models\Query;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Middleware that manages query lifecycle cache keys around job execution.
 *
 * Before the job runs, both the query-level and thread-level cache keys are
 * set so that external consumers (watchdog, in-flight checks) can detect
 * whether a query is actively being processed.
 *
 * After the job finishes — whether successfully or not — the keys are removed.
 *
 * If the job throws an UnrecoverableJobException the query status is set to
 * ERROR before failing the job (preventing retries).
 */
class QueryLifecycleMiddleware
{
    public function __construct(
        private readonly QueryCacheKeyManager $keyManager
    ) {
    }

    /**
     * @param QueryJobContract $job
     */
    public function handle($job, Closure $next): void
    {
        if (! $job instanceof QueryJobContract) {
            $next($job);

            return;
        }

        $threadId = $job->getThreadId();
        $queryId = $job->getQueryId();

        $this->keyManager->markActive($threadId, $queryId);

        Log::info('QueryLifecycle: marking query active', [
            'query_id' => $queryId,
            'thread_id' => $threadId,
        ]);

        try {
            $next($job);
        } catch (UnrecoverableJobException $e) {
            $this->markQueryError($queryId, $e);

            Log::error('QueryLifecycle: unrecoverable exception, query marked as error', [
                'query_id' => $queryId,
                'thread_id' => $threadId,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->keyManager->markInactive($threadId, $queryId);

            Log::info('QueryLifecycle: marking query inactive', [
                'query_id' => $queryId,
                'thread_id' => $threadId,
            ]);
        }
    }

    /**
     * Set the query status to ERROR if it hasn't already been set to a terminal status.
     * Stores the exception class and message in the outcome field for debugging.
     */
    private function markQueryError(int $queryId, \Throwable $e): void
    {
        $query = Query::find($queryId);

        if (! $query) {
            return;
        }

        if (in_array($query->status, [QueryStatus::DONE->value, QueryStatus::ERROR->value], true)) {
            return;
        }

        $query->update([
            'status' => QueryStatus::ERROR,
            'outcome' => get_class($e).': '.$e->getMessage(),
        ]);
    }
}
