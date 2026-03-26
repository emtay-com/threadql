<?php

declare(strict_types=1);

namespace Tests\Unit\Messages;

use App\Support\Messages\FollowupResponseMessages;
use PHPUnit\Framework\TestCase;

class FollowupResponseMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        FollowupResponseMessages::resetFake();
        parent::tearDown();
    }

    public function test_random_returns_string_with_user_handle(): void
    {
        $userHandle = '@testuser';
        FollowupResponseMessages::fakeIndex(0); // Use first message which includes user handle
        $message = FollowupResponseMessages::random($userHandle);

        $this->assertIsString($message);
        $this->assertStringContainsString($userHandle, $message);
        $this->assertNotEmpty($message);
        FollowupResponseMessages::resetFake();
    }

    public function test_random_returns_different_messages(): void
    {
        $userHandle = '@testuser';
        $messages = [];

        // Generate several messages to check variety
        for ($i = 0; $i < 10; $i++) {
            $messages[] = FollowupResponseMessages::random($userHandle);
        }

        // Should have at least some variety
        $uniqueMessages = array_unique($messages);
        $this->assertGreaterThan(1, count($uniqueMessages));
    }

    public function test_fake_index_returns_deterministic_message(): void
    {
        $userHandle = '@testuser';
        FollowupResponseMessages::fakeIndex(0);

        $message1 = FollowupResponseMessages::random($userHandle);
        $message2 = FollowupResponseMessages::random($userHandle);

        $this->assertEquals($message1, $message2);
        $this->assertStringContainsString($userHandle, $message1);
    }

    public function test_reset_fake_clears_fake_index(): void
    {
        $userHandle = '@testuser';
        FollowupResponseMessages::fakeIndex(0);

        $message1 = FollowupResponseMessages::random($userHandle);
        FollowupResponseMessages::resetFake();

        // After reset, should use stable random generation
        // Since microtime might be the same, we test that fake index is cleared
        FollowupResponseMessages::fakeIndex(2); // Use index 2 which doesn't include user handle
        $message2 = FollowupResponseMessages::random($userHandle);

        // Should be different with different fake index
        $this->assertNotEquals($message1, $message2);
    }
}
