<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Slack blocks for tool execution notification messages
 */
class ToolExecutionBlocks implements SlackBlocks
{
    /**
     * Create a new tool execution blocks instance
     */
    public function __construct(
        private readonly string $message
    ) {
    }

    /**
     * Convert the block structure to an array format for Slack API
     *
     * @return array The blocks array to send to Slack
     */
    public function toArray(): array
    {
        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '_'.$this->message.'_',
                ],
            ],
        ];
    }
}
