<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

/**
 * Contract for jobs that process a query within a thread.
 *
 * Implemented by UserQueryInvokerJob and UserFollowUpQueryJob so that
 * middleware (e.g. QueryLifecycleMiddleware) can access identifiers
 * without coupling to concrete job classes.
 */
interface QueryJobContract
{
    public function getQueryId(): int;

    public function getThreadId(): int;
}
