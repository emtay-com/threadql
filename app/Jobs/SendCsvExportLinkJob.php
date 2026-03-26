<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send a Slack message with a CSV export download link
 */
class SendCsvExportLinkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 10;

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
        private readonly string $downloadUrl,
        private readonly int $rowCount,
        private readonly string $expiresAt,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(SlackMessenger $slackMessenger): void
    {
        $this->assignParams(func_get_args());

        $query = Query::with(['thread', 'tenant'])->find($this->queryId);

        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $this->queryId);
        }

        $thread = $query->thread;

        if (! $thread->channel_id || ! $thread->thread_ts) {
            throw new \InvalidArgumentException('Thread missing required fields for Slack notification');
        }

        $message = sprintf(
            "Your CSV export is ready (%s rows).\n<%s|Download CSV>\nThis link expires at %s.",
            number_format($this->rowCount),
            $this->downloadUrl,
            $this->expiresAt,
        );

        $this->slackMessenger->replyInThread($query->tenant, $thread->channel_id, $thread->thread_ts, $message);

        Log::info('CSV export download link sent to Slack', [
            'query_id' => $this->queryId,
            'row_count' => $this->rowCount,
        ]);
    }
}
