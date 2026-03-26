<?php

declare(strict_types=1);

namespace Tests\Unit\Slack;

use App\Jobs\QueryCrashWatchdogJob;
use App\Jobs\UserFollowUpQueryJob;
use App\Jobs\UserQueryInvokerJob;
use App\Slack\Events\QueryJobDispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueryJobDispatcherTest extends TestCase
{
    public function test_it_dispatches_initial_query_job_with_watchdog(): void
    {
        Queue::fake();

        $dispatcher = new QueryJobDispatcher;
        $result = $dispatcher->dispatch(1, 42, false);

        $this->assertEquals('UserQueryInvokerJob', $result);

        Queue::assertPushed(UserQueryInvokerJob::class);
        Queue::assertPushed(QueryCrashWatchdogJob::class, function ($job) {
            return $job->delay > 0;
        });
    }

    public function test_it_dispatches_follow_up_query_job_with_watchdog(): void
    {
        Queue::fake();

        $dispatcher = new QueryJobDispatcher;
        $result = $dispatcher->dispatch(1, 42, true);

        $this->assertEquals('UserFollowUpQueryJob', $result);

        Queue::assertPushed(UserFollowUpQueryJob::class);
        Queue::assertPushed(QueryCrashWatchdogJob::class);
    }
}
