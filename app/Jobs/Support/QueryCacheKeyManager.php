<?php

declare(strict_types=1);

namespace App\Jobs\Support;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Manages cache keys that track actively-running query jobs.
 *
 * Two keys are maintained per active query:
 *  - "query-active:{threadId}:{queryId}" — marks a specific query as running
 *  - "thread-active-query:{threadId}"    — marks a thread as having an active query (stores the queryId)
 *
 * Both keys carry a failsafe TTL so that pod/worker crashes cannot leave
 * stale keys behind indefinitely. Under normal operation the middleware
 * clears the keys explicitly when the job finishes.
 */
class QueryCacheKeyManager
{
    public function __construct(
        private readonly CacheRepository $cache
    ) {
    }

    /**
     * Build the per-query cache key.
     */
    public function queryKey(int $threadId, int $queryId): string
    {
        return "query-active:{$threadId}:{$queryId}";
    }

    /**
     * Build the per-thread cache key.
     */
    public function threadKey(int $threadId): string
    {
        return "thread-active-query:{$threadId}";
    }

    /**
     * Mark a query as actively running (sets both keys with failsafe TTL).
     */
    public function markActive(int $threadId, int $queryId): void
    {
        $ttl = (int) config('threadql.query_active_ttl', 1440);

        $this->cache->put($this->queryKey($threadId, $queryId), true, $ttl);
        $this->cache->put($this->threadKey($threadId), $queryId, $ttl);
    }

    /**
     * Mark a query as no longer running (removes both keys).
     */
    public function markInactive(int $threadId, int $queryId): void
    {
        $this->cache->forget($this->queryKey($threadId, $queryId));
        $this->cache->forget($this->threadKey($threadId));
    }

    /**
     * Check whether a specific query is actively running.
     */
    public function isQueryActive(int $threadId, int $queryId): bool
    {
        return $this->cache->has($this->queryKey($threadId, $queryId));
    }

    /**
     * Check whether any query is actively running in a thread.
     */
    public function hasActiveQueryInThread(int $threadId): bool
    {
        return $this->cache->has($this->threadKey($threadId));
    }
}
