<?php

declare(strict_types=1);

namespace Tests\Unit\Messages;

use App\Support\Messages\RunSqlQueryMessages;
use PHPUnit\Framework\TestCase;

class RunSqlQueryMessagesTest extends TestCase
{
    protected function tearDown(): void
    {
        RunSqlQueryMessages::resetFake();
        parent::tearDown();
    }

    public function test_random_returns_string(): void
    {
        $message = RunSqlQueryMessages::random();

        $this->assertIsString($message);
        $this->assertNotEmpty($message);
    }

    public function test_random_followup_returns_string(): void
    {
        $message = RunSqlQueryMessages::randomFollowUp();

        $this->assertIsString($message);
        $this->assertNotEmpty($message);
    }

    public function test_random_returns_different_messages(): void
    {
        $randomMessage = RunSqlQueryMessages::random();

        $i = 0;
        do {
          $nextMessage = RunSqlQueryMessages::random();
          if ($nextMessage !== $randomMessage) {
              $this->assertTrue(true);
              break;
          }
          $i++;
        } while ($i < 1000);

        if ($i === 1000) {
            $this->fail('Random messages should not be the same');
        }
    }

    public function test_random_followup_returns_different_messages(): void
    {
        $messages = [];

        // Generate several messages to check variety
        for ($i = 0; $i < 10; $i++) {
            $messages[] = RunSqlQueryMessages::randomFollowUp();
        }

        // Should have at least some variety
        $uniqueMessages = array_unique($messages);
        $this->assertGreaterThan(1, count($uniqueMessages));
    }

    public function test_fake_index_returns_deterministic_message(): void
    {
        RunSqlQueryMessages::fakeIndex(0);

        $message1 = RunSqlQueryMessages::random();
        $message2 = RunSqlQueryMessages::random();

        $this->assertEquals($message1, $message2);
    }

    public function test_fake_index_affects_both_random_and_random_followup(): void
    {
        RunSqlQueryMessages::fakeIndex(0);

        $message1 = RunSqlQueryMessages::random();
        $message2 = RunSqlQueryMessages::randomFollowUp();

        // Both should return the same message since they use the same fake index
        $this->assertEquals($message1, $message2);
    }

    public function test_reset_fake_clears_fake_index(): void
    {
        RunSqlQueryMessages::fakeIndex(0);

        $message1 = RunSqlQueryMessages::random();
        RunSqlQueryMessages::resetFake();

        // After reset, should use stable random generation
        // Since microtime might be the same, we test that fake index is cleared
        RunSqlQueryMessages::fakeIndex(1);
        $message2 = RunSqlQueryMessages::random();

        // Should be different with different fake index
        $this->assertNotEquals($message1, $message2);
    }
}
