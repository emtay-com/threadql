<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

use JoliCode\Slack\Api\Client;
use JoliCode\Slack\ClientFactory;

/**
 * Factory for creating tenant-scoped Slack clients
 */
class SlackClientFactory
{
    private ClientFactory $clientFactory;

    public function __construct()
    {
        $this->clientFactory = new ClientFactory();
    }

    /**
     * Create a new Slack client with the given bot token
     */
    public function create(string $botToken): Client
    {
        return $this->clientFactory->create($botToken);
    }
}
