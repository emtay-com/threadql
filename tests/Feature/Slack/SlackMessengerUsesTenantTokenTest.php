<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackClientFactory;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Tenant;
use Mockery;
use Tests\TestCase;

/**
 * Test that SlackMessenger uses tenant-specific tokens
 */
class SlackMessengerUsesTenantTokenTest extends TestCase
{
    private SlackMessenger $messenger;

    private SlackClientFactory $mockFactory;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the factory to track what token is used
        $this->mockFactory = Mockery::mock(SlackClientFactory::class);
        $this->messenger = new SlackMessenger(null, $this->mockFactory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that SlackMessenger creates client with tenant's bot token
     */
    public function test_slack_messenger_uses_tenant_bot_token(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => 'xoxb-test-123456789-abcdefghijklmnopqrstuvwx',
        ]);

        // Create a mock that implements the Client interface
        $mockClient = Mockery::mock('JoliCode\Slack\Api\Client');
        $mockResponse = Mockery::mock();
        $mockResponse->shouldReceive('getOk')
            ->andReturn(true);
        $mockResponse->shouldReceive('getTs')
            ->andReturn('1234567890.123456');
        $mockClient->shouldReceive('chatPostMessage')
            ->andReturn($mockResponse);

        // Expect factory to be called with tenant's token
        $this->mockFactory
            ->shouldReceive('create')
            ->once()
            ->with($tenant->slack_bot_token)
            ->andReturn($mockClient);

        // Call a method that requires a client
        $result = $this->messenger->replyInThread($tenant, 'C1234567890', '1234567890.123456', 'Test message');

        $this->assertIsArray($result);
        $this->assertEquals('1234567890.123456', $result['ts']);
    }

    /**
     * Test that missing bot token throws exception
     */
    public function test_missing_bot_token_throws_exception(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tenant does not have a Slack bot token configured');

        $this->messenger->replyInThread($tenant, 'C1234567890', '1234567890.123456', 'Test message');
    }
}
