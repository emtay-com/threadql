<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Slack blocks for requesting a business definition from the user
 */
class RequestDefinitionBlocks implements SlackBlocks
{
    /**
     * Create a new request definition blocks instance
     */
    public function __construct(
        private readonly string $subject,
        private readonly int $queryId
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
                    'text' => "We need a definition for \"{$this->subject}\".\nTap below to provide it.",
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Provide definition',
                            'emoji' => true,
                        ],
                        'style' => 'primary',
                        'action_id' => "request_definition_{$this->queryId}",
                        'value' => $this->subject,
                    ],
                ],
            ],
        ];
    }
}
