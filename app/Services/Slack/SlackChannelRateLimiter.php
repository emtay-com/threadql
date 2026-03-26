<?php

declare(strict_types=1);

namespace App\Services\Slack;

use Illuminate\Support\Facades\Cache;

/**
 * Rate limiter for Slack channel messages.
 *
 * Tracks when the last message was sent per channel and provides
 * helpers to either wait for the rate-limit window to pass (result jobs)
 * or check whether a window is active (notify jobs).
 *
 * Uses the standard Cache facade so it works with any configured driver
 * (Redis, Memcached, etc.).
 */
class SlackChannelRateLimiter
{
    private const string KEY_PREFIX = 'slack_rate:';

    /**
     * Minimum gap between messages on the same channel, in milliseconds.
     */
    private const int WINDOW_MS = 1100;

    /**
     * Returns the remaining milliseconds of the current rate-limit window,
     * or 0 if the channel is free to send immediately.
     *
     * @param string $channelId Slack channel ID
     */
    public function remainingMs(string $channelId): int
    {
        $expiresAtMs = Cache::get($this->key($channelId));

        if ($expiresAtMs === null) {
            return 0;
        }

        return max(0, $expiresAtMs - $this->nowMs());
    }

    /**
     * Acquires the rate-limit slot for this channel.
     * Must be called immediately before every Slack API send.
     *
     * @param string $channelId Slack channel ID
     */
    public function acquire(string $channelId): void
    {
        // TTL of 2 s is a safety margin over the 1.1 s window to ensure the
        // key is never evicted before the window closes.
        Cache::put($this->key($channelId), $this->nowMs() + self::WINDOW_MS, 2);
    }

    private function key(string $channelId): string
    {
        return self::KEY_PREFIX.$channelId;
    }

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
