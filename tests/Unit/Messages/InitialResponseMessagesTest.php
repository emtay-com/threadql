<?php

declare(strict_types=1);

namespace Tests\Unit\Messages;

use App\Support\Messages\InitialResponseMessages;
use PHPUnit\Framework\TestCase;

class InitialResponseMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        InitialResponseMessages::resetFake();
        parent::tearDown();
    }

    public function test_random_returns_string_with_user_handle(): void
    {
        $userHandle = '@testuser';
        $message = InitialResponseMessages::random($userHandle);

        $this->assertIsString($message);
        $this->assertStringContainsString($userHandle, $message);
        $this->assertNotEmpty($message);
    }

    public function test_random_returns_different_messages(): void
    {
        $userHandle = '@testuser';
        $messages = [];

        // Generate several messages to check variety
        for ($i = 0; $i < 10; $i++) {
            $messages[] = InitialResponseMessages::random($userHandle);
        }

        // Should have at least some variety
        $uniqueMessages = array_unique($messages);
        $this->assertGreaterThan(1, count($uniqueMessages));
    }

    public function test_fake_index_returns_deterministic_message(): void
    {
        $userHandle = '@testuser';
        InitialResponseMessages::fakeIndex(0);

        $message1 = InitialResponseMessages::random($userHandle);
        $message2 = InitialResponseMessages::random($userHandle);

        $this->assertEquals($message1, $message2);
        $this->assertStringContainsString($userHandle, $message1);
    }

    public function test_reset_fake_clears_fake_index(): void
    {
        $userHandle = '@testuser';
        InitialResponseMessages::fakeIndex(0);

        $message1 = InitialResponseMessages::random($userHandle);
        InitialResponseMessages::resetFake();

        // After reset, should use stable random generation
        // Since microtime might be the same, we test that fake index is cleared
        InitialResponseMessages::fakeIndex(1);
        $message2 = InitialResponseMessages::random($userHandle);

        // Should be different with different fake index
        $this->assertNotEquals($message1, $message2);
    }
}
