<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to send "no results found" message to Slack
 */
class SendNoResultsMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 5;

    #[Assignable]
    private SlackMessenger $slackMessenger;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new FailOnUnrecoverableException];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $queryId
    ) {
    }

    /**
     * Get the query ID.
     */
    public function getQueryId(): int
    {
        return $this->queryId;
    }

    /**
     * Execute the job.
     */
    public function handle(SlackMessenger $slackMessenger): void
    {
        $this->assignParams(func_get_args());

        $query = $this->loadQueryWithThread();
        $thread = $query->thread;

        if (! $this->isThreadValid($thread)) {
            Log::warning('Thread missing required fields for no results notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'channel_id' => $thread->channel_id,
                'thread_ts' => $thread->thread_ts,
            ]);

            return;
        }

        // External boundary: Slack API
        try {
            $this->sendNoResultsNotification($query->tenant, $thread);
        } catch (Throwable $e) {
            Log::error('Error sending no results notification', [
                'query_id' => $this->queryId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Load query with thread and tenant relationships.
     */
    private function loadQueryWithThread(): Query
    {
        return $this->findQueryOrFail($this->queryId);
    }

    /**
     * Check if thread has required fields for notification.
     */
    private function isThreadValid(Thread $thread): bool
    {
        return ! empty($thread->channel_id) && ! empty($thread->thread_ts);
    }

    /**
     * Send no results notification to Slack.
     */
    private function sendNoResultsNotification(Tenant $tenant, Thread $thread): void
    {
        $result = $this->slackMessenger->replyInThreadWithBlocks(
            $tenant,
            $thread->channel_id,
            $thread->thread_ts,
            '_no results found_',
            [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '_no results found_',
                ],
            ]]
        );

        if ($result) {
            Log::info('No results notification sent', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'message_ts' => $result['ts'],
            ]);
        } else {
            Log::warning('Failed to send no results notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
            ]);
        }
    }

    /**
     * Find query or throw EntityNotFoundException.
     */
    private function findQueryOrFail(int $queryId): Query
    {
        $query = Query::with('thread', 'tenant')->find($queryId);
        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }
}
