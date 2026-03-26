<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Query Crash Watchdog Delay
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before (re-)checking whether a query job has finished.
    | The watchdog is dispatched with this delay alongside every query job
    | and re-dispatches itself until the query finishes or the cache key
    | disappears (indicating a crash).
    |
    */
    'query_crash_watchdog_delay' => (int) env('QUERY_CRASH_WATCHDOG_DELAY', 300),

    /*
    |--------------------------------------------------------------------------
    | Query Active TTL (failsafe)
    |--------------------------------------------------------------------------
    |
    | Failsafe TTL in seconds for the cache keys that mark a query as
    | actively running. Under normal operation the QueryLifecycleMiddleware
    | clears keys explicitly, but this TTL protects against pod/worker
    | crashes where cleanup never runs. Matches the job timeout (1440s).
    |
    */
    'query_active_ttl' => (int) env('QUERY_ACTIVE_TTL', 1440),
];
