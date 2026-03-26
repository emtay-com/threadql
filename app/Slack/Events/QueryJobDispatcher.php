<?php

declare(strict_types=1);

namespace App\Slack\Events;

use App\Jobs\QueryCrashWatchdogJob;
use App\Jobs\UserFollowUpQueryJob;
use App\Jobs\UserQueryInvokerJob;

/**
 * Dispatches appropriate job based on query type
 */
class QueryJobDispatcher
{
    /**
     * Dispatch appropriate job for query
     *
     * @return string Job type that was dispatched
     */
    public function dispatch(int $threadId, int $queryId, bool $isFollowUp): string
    {
        if ($isFollowUp) {
            UserFollowUpQueryJob::dispatch($threadId, $queryId);
        } else {
            UserQueryInvokerJob::dispatch($threadId, $queryId);
        }

        QueryCrashWatchdogJob::dispatch($queryId, $threadId)
            ->delay(config('threadql.query_crash_watchdog_delay', 300));

        return $isFollowUp ? 'UserFollowUpQueryJob' : 'UserQueryInvokerJob';
    }
}
