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
 * Job to send "no datasource" notification to Slack
 */
class SendNoDatasourceNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 1;

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
     * Execute the job.
     */
    public function handle(SlackMessenger $slackMessenger): void
    {
        $this->assignParams(func_get_args());

        $query = $this->loadQueryWithThread();
        $thread = $query->thread;

        if (! $this->isThreadValid($thread)) {
            Log::warning('Thread missing required fields for no datasource notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'channel_id' => $thread->channel_id,
                'thread_ts' => $thread->thread_ts,
            ]);

            return;
        }

        try {
            $this->sendNotification($query->tenant, $thread);
        } catch (Throwable $e) {
            Log::error('Error sending no datasource notification', [
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
        $query = Query::with('thread', 'tenant')->find($this->queryId);
        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $this->queryId);
        }

        return $query;
    }

    /**
     * Check if thread has required fields for notification.
     */
    private function isThreadValid(Thread $thread): bool
    {
        return ! empty($thread->channel_id) && ! empty($thread->thread_ts);
    }

    /**
     * Send no datasource notification to Slack.
     */
    private function sendNotification(Tenant $tenant, Thread $thread): void
    {
        $message = 'No datasource is currently configured for this workspace. Please configure one in the admin panel.';

        $result = $this->slackMessenger->replyInThreadWithBlocks(
            $tenant,
            $thread->channel_id,
            $thread->thread_ts,
            $message,
            [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $message,
                ],
            ]]
        );

        if ($result) {
            Log::info('No datasource notification sent', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'message_ts' => $result['ts'],
            ]);
        } else {
            Log::warning('Failed to send no datasource notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
            ]);
        }
    }
}
