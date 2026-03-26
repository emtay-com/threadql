<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueryStatus;
use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessenger;
use App\Infrastructure\Slack\ToolExecutionBlocks;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Slack\SlackChannelRateLimiter;
use App\Support\Messages\ExportCsvMessages;
use App\Support\Messages\FetchTableDdlsMessages;
use App\Support\Messages\RequestDefinitionMessages;
use App\Support\Messages\RunQueryForCsvExportMessages;
use App\Support\Messages\RunSqlQueryMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to notify Slack that a tool is executing with a custom message
 */
class NotifyToolExecutingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * Maximum number of rate-limit re-dispatch cycles before giving up.
     */
    private const int MAX_RATE_LIMIT_ATTEMPTS = 10;

    #[Assignable]
    private SlackMessenger $slackMessenger;

    #[Assignable]
    private SlackChannelRateLimiter $rateLimiter;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new FailOnUnrecoverableException];
    }

    /**
     * Create a new job instance.
     *
     * @param int $rateLimitAttempts Number of times this job has been re-dispatched due to rate limiting
     */
    public function __construct(
        private readonly int $queryId,
        private readonly string $toolName,
        private readonly int $rateLimitAttempts = 0,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(SlackMessenger $slackMessenger, SlackChannelRateLimiter $rateLimiter): void
    {
        $this->assignParams(func_get_args());

        $query = $this->loadQueryWithThread();
        $thread = $query->thread;

        // Guard: skip if the query has already finished. Runs on every execution
        // — including re-dispatched rate-limit retries — so a query that finishes
        // while cycling through retries is caught before the notification fires.
        if ($this->isQueryFinished($query)) {
            Log::info('Skipping notification — query already finished', [
                'query_id' => $this->queryId,
                'status' => $query->status,
            ]);

            return;
        }

        if (! $this->isThreadValid($thread)) {
            Log::warning('Thread missing required fields for notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'channel_id' => $thread->channel_id,
                'last_message_ts' => $thread->last_message_ts,
            ]);

            return;
        }

        $message = $this->generateMessage($query);

        // Rate-limit check: re-dispatch instead of sleeping so the worker slot
        // is freed immediately.
        if ($this->rateLimiter->remainingMs($thread->channel_id) > 0) {
            if ($this->rateLimitAttempts >= self::MAX_RATE_LIMIT_ATTEMPTS) {
                Log::warning('Skipping stale notification after max rate-limit retries', [
                    'query_id' => $this->queryId,
                    'tool_name' => $this->toolName,
                    'attempts' => $this->rateLimitAttempts,
                ]);

                return;
            }

            static::dispatch($this->queryId, $this->toolName, $this->rateLimitAttempts + 1)->delay(1);

            return;
        }

        $this->rateLimiter->acquire($thread->channel_id);

        // External boundary: Slack API
        try {
            $this->sendNotification($query->tenant, $thread, $message);
        } catch (Throwable $e) {
            Log::error('Error sending tool execution notification', [
                'query_id' => $this->queryId,
                'tool_name' => $this->toolName,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Load query with thread relationship.
     */
    private function loadQueryWithThread(): Query
    {
        return $this->findQueryOrFail($this->queryId);
    }

    /**
     * Determine whether the query has reached a terminal state.
     */
    private function isQueryFinished(Query $query): bool
    {
        return in_array($query->status, [QueryStatus::DONE->value, QueryStatus::ERROR->value], true);
    }

    /**
     * Check if thread has required fields for notification.
     */
    private function isThreadValid(Thread $thread): bool
    {
        return ! empty($thread->channel_id) && ! empty($thread->last_message_ts);
    }

    /**
     * Generate message for the tool execution.
     */
    private function generateMessage(Query $query): string
    {
        $isFollowUp = $this->isFollowUpQuery($query);

        return match ($this->toolName) {
            'run_sql_query' => $isFollowUp ? RunSqlQueryMessages::randomFollowUp() : RunSqlQueryMessages::random(),
            'fetch_table_ddls' => FetchTableDdlsMessages::random(),
            'export_csv' => ExportCsvMessages::random(),
            'request_definition' => RequestDefinitionMessages::random(),
            'run_query_for_csv_export' => $isFollowUp ? RunQueryForCsvExportMessages::randomFollowUp() : RunQueryForCsvExportMessages::random(),
            default => 'Working on it…',
        };
    }

    /**
     * Determine if this is a follow-up query.
     */
    private function isFollowUpQuery(Query $query): bool
    {
        $thread = $query->thread;
        if (! $thread) {
            return false;
        }

        return $thread->queries()
            ->where('status', QueryStatus::DONE->value)
            ->where('id', '!=', $query->id)
            ->exists();
    }

    /**
     * Send tool execution notification to Slack.
     */
    private function sendNotification(Tenant $tenant, Thread $thread, string $message): void
    {
        $blocks = new ToolExecutionBlocks($message);

        $result = $this->slackMessenger->replyInThreadWithBlocks(
            $tenant,
            $thread->channel_id,
            $thread->last_message_ts,
            $message, // fallback text
            $blocks->toArray()
        );

        if ($result) {
            Log::info('Tool execution notification sent', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'tool_name' => $this->toolName,
                'message' => $message,
                'message_ts' => $result['ts'],
            ]);
        } else {
            Log::warning('Failed to send tool execution notification', [
                'thread_id' => $thread->id,
                'query_id' => $this->queryId,
                'tool_name' => $this->toolName,
                'message' => $message,
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
