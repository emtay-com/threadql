<?php

declare(strict_types=1);

namespace App\Slack\Events;

use App\Enums\QueryStatus;
use App\Models\Query;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Creates query records from Slack events
 */
class QueryCreator
{
    /**
     * Create query from event data
     *
     * @return array{query: Query|null, duplicate: bool}
     */
    public function createQuery(int $tenantId, int $threadId, int $slackUserId, array $eventData): array
    {
        try {
            $query = Query::create([
                'tenant_id' => $tenantId,
                'thread_id' => $threadId,
                'slack_event_id' => $eventData['event_id'],
                'channel_id' => $eventData['channel_id'],
                'message_ts' => $eventData['message_ts'],
                'status' => QueryStatus::RECEIVED->value,
                'user_id' => $eventData['user_id'],
                'raw_text' => $eventData['text'],
                'slack_user_id' => $slackUserId,
            ]);

            return [
                'query' => $query,
                'duplicate' => false,
            ];
        } catch (QueryException $e) {
            // Handle duplicate slack_event_id
            if ($this->isDuplicateEventError($e)) {
                Log::info('Duplicate Slack event received, ignoring', [
                    'event_id' => $eventData['event_id'],
                ]);

                return [
                    'query' => null,
                    'duplicate' => true,
                ];
            }

            throw $e;
        }
    }

    /**
     * Check if exception is due to duplicate event_id
     */
    private function isDuplicateEventError(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'queries_slack_event_id_unique');
    }
}
