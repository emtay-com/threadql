<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Services\Slack\SlackChannelRateLimiter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Send Slack blocks message in a thread
 */
final class SendSlackBlocks implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 30;

    #[Assignable]
    private SlackMessenger $messenger;

    #[Assignable]
    private SlackChannelRateLimiter $rateLimiter;

    public function __construct(
        public int $queryId,
        public string $channelId,
        public string $threadTs,
        public string $text,
        public array $blocks
    ) {
    }

    public function handle(SlackMessenger $messenger, SlackChannelRateLimiter $rateLimiter): void
    {
        $this->assignParams(func_get_args());
        /** @var Query $query */
        $query = Query::findOrFail($this->queryId);

        $remaining = $this->rateLimiter->remainingMs($this->channelId);
        if ($remaining > 0) {
            usleep($remaining * 1000);
        }

        $this->rateLimiter->acquire($this->channelId);

        $this->messenger->replyInThreadWithBlocks(
            $query->tenant,
            $this->channelId,
            $this->threadTs,
            $this->text,
            $this->blocks
        );
    }
}
