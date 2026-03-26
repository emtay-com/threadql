<?php

declare(strict_types=1);

namespace App\Slack;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\FeedbackSurveyBlocks;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Feedback Messenger for posting Yes/No survey buttons after query results
 */
class FeedbackMessenger
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger
    ) {
    }

    /**
     * Post feedback survey buttons for a completed query
     *
     * @param int $queryId The query ID to post feedback for
     */
    public function postForQuery(int $queryId): void
    {
        try {
            $query = $this->findQueryOrFail($queryId);
            $thread = $query->thread;

            if (! $thread->channel_id || ! $thread->last_message_ts) {
                Log::warning('Thread missing channel_id or last_message_ts for feedback posting', [
                    'thread_id' => $thread->id,
                    'query_id' => $queryId,
                    'channel_id' => $thread->channel_id,
                    'last_message_ts' => $thread->last_message_ts,
                ]);

                return;
            }

            $blocks = new FeedbackSurveyBlocks($queryId);

            $result = $this->slackMessenger->replyInThreadWithBlocks(
                $thread->tenant,
                $thread->channel_id,
                $thread->last_message_ts,
                'Was this result helpful?', // fallback text
                $blocks->toArray()
            );

            if ($result) {
                Log::info('Feedback survey posted successfully', [
                    'thread_id' => $thread->id,
                    'query_id' => $queryId,
                    'message_ts' => $result['ts'],
                ]);
            } else {
                Log::warning('Failed to post feedback survey', [
                    'thread_id' => $thread->id,
                    'query_id' => $queryId,
                ]);
            }
        } catch (Exception $e) {
            Log::error('Error posting feedback survey', [
                'query_id' => $queryId,
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
        $query = Query::with('thread')->find($queryId);
        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }
}
