<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\RequestDefinitionBlocks;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to request a business definition from the user via Slack button and modal
 */
class RequestDefinitionJob implements ShouldQueue
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
        private readonly int $queryId,
        private readonly string $subject
    ) {
    }

    /**
     * Get the query ID for testing
     */
    public function getQueryId(): int
    {
        return $this->queryId;
    }

    /**
     * Get the subject for testing
     */
    public function getSubject(): string
    {
        return $this->subject;
    }

    /**
     * Execute the job.
     */
    public function handle(SlackMessenger $slackMessenger): void
    {
        $this->assignParams(func_get_args());

        try {
            $query = $this->findQueryOrFail($this->queryId);
            $thread = $query->thread;

            if (! $thread->channel_id || ! $thread->last_message_ts) {
                Log::warning('Thread missing channel_id or last_message_ts', [
                    'thread_id' => $thread->id,
                    'query_id' => $this->queryId,
                    'channel_id' => $thread->channel_id,
                    'last_message_ts' => $thread->last_message_ts,
                ]);

                return;
            }

            $blocks = new RequestDefinitionBlocks($this->subject, $this->queryId);

            $result = $this->slackMessenger->replyInThreadWithBlocks(
                $query->tenant,
                $thread->channel_id,
                $thread->last_message_ts,
                "We need a definition for \"{$this->subject}\". Tap the button to provide it.", // fallback text
                $blocks->toArray()
            );

            if ($result) {
                Log::info('Definition request sent', [
                    'thread_id' => $thread->id,
                    'query_id' => $this->queryId,
                    'subject' => $this->subject,
                    'message_ts' => $result['ts'],
                ]);
            } else {
                Log::warning('Failed to send definition request', [
                    'thread_id' => $thread->id,
                    'query_id' => $this->queryId,
                    'subject' => $this->subject,
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error sending definition request', [
                'query_id' => $this->queryId,
                'subject' => $this->subject,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Find query or throw EntityNotFoundException.
     */
    private function findQueryOrFail(int $queryId): Query
    {
        $query = Query::with(['thread', 'tenant'])->find($queryId);
        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }
}
