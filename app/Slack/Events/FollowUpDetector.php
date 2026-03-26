<?php

declare(strict_types=1);

namespace App\Slack\Events;

use App\Enums\QueryStatus;
use App\Models\Query;
use App\Models\Thread;

/**
 * Detects if a query is a follow-up in an existing conversation
 */
class FollowUpDetector
{
    /**
     * Determine if this is a follow-up query in an existing thread
     */
    public function isFollowUp(Thread $thread, string $messageTs, ?string $threadTs): bool
    {
        // Only consider follow-ups if this is posted in an existing thread
        if (! $this->isInThread($messageTs, $threadTs)) {
            return false;
        }

        // Check if the thread has at least one completed query
        return $this->hasCompletedQuery($thread);
    }

    /**
     * Check if message is in a thread (not a new top-level message)
     */
    private function isInThread(string $messageTs, ?string $threadTs): bool
    {
        return $threadTs !== null && $threadTs !== $messageTs;
    }

    /**
     * Check if thread has a query currently being processed (not yet done or errored)
     */
    public function hasInFlightQuery(Thread $thread): bool
    {
        return Query::where('thread_id', $thread->id)
            ->whereNotIn('status', [QueryStatus::DONE->value, QueryStatus::ERROR->value])
            ->exists();
    }

    /**
     * Check if thread has at least one completed query
     */
    private function hasCompletedQuery(Thread $thread): bool
    {
        return Query::where('thread_id', $thread->id)
            ->where('status', QueryStatus::DONE->value)
            ->exists();
    }
}
