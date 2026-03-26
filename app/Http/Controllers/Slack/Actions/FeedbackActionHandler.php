<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack\Actions;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\SlackBlocksManipulator;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles feedback vote button actions
 */
class FeedbackActionHandler
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger
    ) {
    }

    /**
     * Handle feedback vote
     */
    public function handle(int $queryId, string $vote, array $payload, Tenant $tenant): Response
    {
        $score = $this->voteToScore($vote);

        if ($score === 0) {
            Log::warning('Invalid vote value', [
                'vote' => $vote,
                'query_id' => $queryId,
            ]);

            return response()->noContent();
        }

        $this->updateQueryScore($queryId, $score);
        $this->updateFeedbackMessage($tenant, $payload);

        Log::info('Successfully processed feedback vote', [
            'query_id' => $queryId,
            'vote' => $vote,
            'score' => $score,
        ]);

        return response()->noContent();
    }

    /**
     * Convert vote string to score
     */
    private function voteToScore(string $vote): int
    {
        return match ($vote) {
            'yes' => 1,
            'no' => -1,
            default => 0,
        };
    }

    /**
     * Update the score for a query
     */
    private function updateQueryScore(int $queryId, int $score): void
    {
        $query = Query::find($queryId);

        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        $query->update([
            'score' => $score,
        ]);
    }

    /**
     * Update the feedback message to show thanks
     */
    private function updateFeedbackMessage(Tenant $tenant, array $payload): void
    {
        $messageData = $this->extractMessageData($payload);

        if (! $this->isValidMessageData($messageData)) {
            return;
        }

        $updatedBlocks = $this->buildThankYouResponse($messageData['blocks']);

        $this->sendUpdatedMessage($tenant, $messageData, $updatedBlocks, $payload);
    }

    /**
     * Extract message data from payload
     *
     * @return array{channel: string|null, ts: string|null, blocks: array}
     */
    private function extractMessageData(array $payload): array
    {
        return [
            'channel' => $payload['channel']['id'] ?? null,
            'ts' => $payload['message']['ts'] ?? ($payload['container']['message_ts'] ?? null),
            'blocks' => $payload['message']['blocks'] ?? [],
        ];
    }

    /**
     * Check if message data is valid for update
     *
     * @param array{channel: string|null, ts: string|null, blocks: array} $messageData
     */
    private function isValidMessageData(array $messageData): bool
    {
        return ! empty($messageData['channel']) && ! empty($messageData['ts']);
    }

    /**
     * Build thank you response blocks
     *
     * @param array<int, array<string, mixed>> $originalBlocks
     * @return array<int, array<string, mixed>>
     */
    private function buildThankYouResponse(array $originalBlocks): array
    {
        return SlackBlocksManipulator::replaceActionsWithThankYou($originalBlocks);
    }

    /**
     * Send updated message to Slack
     *
     * @param array{channel: string|null, ts: string|null, blocks: array} $messageData
     * @param array<int, array<string, mixed>> $blocks
     */
    private function sendUpdatedMessage(Tenant $tenant, array $messageData, array $blocks, array $payload): void
    {
        try {
            $this->slackMessenger->updateMessage(
                $tenant,
                $messageData['channel'],
                $messageData['ts'],
                '_Thanks for the feedback!_',
                $blocks
            );
        } catch (Exception $e) {
            $this->logUpdateFailure($e, $payload);
        }
    }

    /**
     * Log failure to update message
     */
    private function logUpdateFailure(Exception $exception, array $payload): void
    {
        Log::warning('Failed to update feedback message', [
            'query_id' => $payload['actions'][0]['value'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
