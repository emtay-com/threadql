<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Slack;

use App\Services\Slack\SlackChannelRateLimiter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SlackChannelRateLimiterTest extends TestCase
{
    private SlackChannelRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = new SlackChannelRateLimiter;
    }

    public function test_remaining_ms_returns_zero_when_no_key_set(): void
    {
        Cache::forget('slack_rate:C123');

        $this->assertSame(0, $this->limiter->remainingMs('C123'));
    }

    public function test_remaining_ms_returns_positive_when_window_is_active(): void
    {
        // Store an expiry 500 ms in the future
        $expiresAtMs = (int) (microtime(true) * 1000) + 500;
        Cache::put('slack_rate:C123', $expiresAtMs, 2);

        $remaining = $this->limiter->remainingMs('C123');

        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(500, $remaining);
    }

    public function test_remaining_ms_returns_zero_when_window_has_expired(): void
    {
        // Store an expiry 200 ms in the past
        $expiresAtMs = (int) (microtime(true) * 1000) - 200;
        Cache::put('slack_rate:C123', $expiresAtMs, 2);

        $this->assertSame(0, $this->limiter->remainingMs('C123'));
    }

    public function test_acquire_stores_expiry_timestamp_with_two_second_ttl(): void
    {
        Cache::forget('slack_rate:C999');

        $beforeMs = (int) (microtime(true) * 1000);
        $this->limiter->acquire('C999');
        $afterMs = (int) (microtime(true) * 1000);

        $stored = Cache::get('slack_rate:C999');
        $this->assertNotNull($stored);

        // The stored value should be approximately nowMs + 1100 ms
        $this->assertGreaterThanOrEqual($beforeMs + 1100, $stored);
        $this->assertLessThanOrEqual($afterMs + 1100, $stored);
    }

    public function test_acquire_clears_rate_limit_window(): void
    {
        Cache::forget('slack_rate:C777');

        // Before acquire: channel is free
        $this->assertSame(0, $this->limiter->remainingMs('C777'));

        $this->limiter->acquire('C777');

        // After acquire: channel is rate-limited
        $this->assertGreaterThan(0, $this->limiter->remainingMs('C777'));
    }

    public function test_remaining_ms_uses_channel_specific_key(): void
    {
        Cache::forget('slack_rate:CHANNEL_A');
        Cache::forget('slack_rate:CHANNEL_B');

        // Lock only channel A
        $this->limiter->acquire('CHANNEL_A');

        $this->assertGreaterThan(0, $this->limiter->remainingMs('CHANNEL_A'));
        $this->assertSame(0, $this->limiter->remainingMs('CHANNEL_B'));
    }
}
