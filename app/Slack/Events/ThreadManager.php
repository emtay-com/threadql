<?php

declare(strict_types=1);

namespace App\Slack\Events;

use App\Enums\ThreadStatus;
use App\Models\Thread;

/**
 * Manages Slack thread lifecycle
 */
class ThreadManager
{
    /**
     * Find or create a thread
     */
    public function findOrCreateThread(
        int $tenantId,
        string $teamId,
        string $channelId,
        string $threadTs,
        string $starterUserId
    ): Thread {
        return Thread::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'team_id' => $teamId,
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
            ],
            [
                'starter_user_id' => $starterUserId,
                'status' => ThreadStatus::ACTIVE->value,
            ]
        );
    }

    /**
     * Update thread's last message timestamp
     */
    public function updateLastMessageTs(Thread $thread, string $messageTs): void
    {
        $thread->update([
            'last_message_ts' => $messageTs,
        ]);
    }
}
