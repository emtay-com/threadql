<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Infrastructure\Slack\SlackClientFactory;
use App\Infrastructure\Slack\SlackMessenger;
use App\Providers\SlackServiceProvider;
use JoliCode\Slack\Api\Client;
use Tests\TestCase;

/**
 * Test that SlackServiceProvider does not create global client
 */
class SlackServiceProviderNoGlobalClientTest extends TestCase
{
    /**
     * Test that no global Slack client is bound
     */
    public function test_no_global_slack_client_is_bound(): void
    {
        // The service provider should not bind a global Client
        $this->assertFalse(app()->bound(Client::class));
    }

    /**
     * Test that SlackClientFactory is bound
     */
    public function test_slack_client_factory_is_bound(): void
    {
        $factory = app(SlackClientFactory::class);
        $this->assertInstanceOf(SlackClientFactory::class, $factory);
    }

    /**
     * Test that SlackMessenger is bound with factory
     */
    public function test_slack_messenger_is_bound_with_factory(): void
    {
        $messenger = app(SlackMessenger::class);
        $this->assertInstanceOf(SlackMessenger::class, $messenger);

        // Verify it has a factory (not a pre-configured client)
        $reflection = new \ReflectionClass($messenger);
        $factoryProperty = $reflection->getProperty('factory');
        $factoryProperty->setAccessible(true);
        $factory = $factoryProperty->getValue($messenger);

        $this->assertInstanceOf(SlackClientFactory::class, $factory);
    }
}
