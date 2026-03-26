<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send ephemeral SQL debug message to a user in a Slack channel
 */
final class SendEphemeralSqlDebug implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $queryId,
        public string $channelId,
        public string $userId,
        public string $text,
    ) {
    }

    public function handle(SlackMessenger $messenger): void
    {
        $query = Query::with(['tenant'])->find($this->queryId);

        if (! $query) {
            Log::warning('SendEphemeralSqlDebug: Query not found', [
                'query_id' => $this->queryId,
            ]);

            return;
        }

        if (! $query->tenant) {
            Log::warning('SendEphemeralSqlDebug: Query tenant not found', [
                'query_id' => $this->queryId,
            ]);

            return;
        }

        $messenger->sendEphemeral($query->tenant, $this->channelId, $this->userId, $this->text);
    }
}
