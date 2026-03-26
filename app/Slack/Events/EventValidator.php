<?php

declare(strict_types=1);

namespace App\Slack\Events;

use Illuminate\Support\Facades\Log;

/**
 * Validates Slack event payloads
 */
class EventValidator
{
    /**
     * Validate app_mention event has required fields
     *
     * @return array{valid: bool, missing: array}
     */
    public function validateAppMention(array $payload, array $event): array
    {
        $teamId = $payload['team_id'] ?? null;
        $channelId = $event['channel'] ?? null;
        $messageTs = $event['ts'] ?? null;
        $userId = $event['user'] ?? null;

        $missing = [];

        if (! $teamId) {
            $missing[] = 'team_id';
        }
        if (! $channelId) {
            $missing[] = 'channel_id';
        }
        if (! $messageTs) {
            $missing[] = 'message_ts';
        }
        if (! $userId) {
            $missing[] = 'user_id';
        }

        if (! empty($missing)) {
            Log::warning('Missing required fields in app_mention event', [
                'team_id' => $teamId,
                'channel_id' => $channelId,
                'message_ts' => $messageTs,
                'user_id' => $userId,
                'missing_fields' => $missing,
            ]);
        }

        return [
            'valid' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Extract event data from payload
     */
    public function extractEventData(array $payload, array $event): array
    {
        $messageTs = $event['ts'] ?? null;

        return [
            'team_id' => $payload['team_id'] ?? null,
            'channel_id' => $event['channel'] ?? null,
            'message_ts' => $messageTs,
            'thread_ts' => $event['thread_ts'] ?? $messageTs,
            'user_id' => $event['user'] ?? null,
            'text' => $event['text'] ?? '',
            'event_id' => $payload['event_id'] ?? null,
        ];
    }
}
