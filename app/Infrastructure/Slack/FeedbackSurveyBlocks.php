<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Slack blocks for posting Yes/No feedback survey buttons
 */
class FeedbackSurveyBlocks implements SlackBlocks
{
    /**
     * Create a new feedback survey blocks instance
     */
    public function __construct(
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
                    'text' => '*Was this result helpful?*',
                ],
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'Yes',
                            'emoji' => true,
                        ],
                        'style' => 'primary',
                        'action_id' => "yes_button_{$this->queryId}",
                        'value' => 'yes',
                    ],
                    [
                        'type' => 'button',
                        'text' => [
                            'type' => 'plain_text',
                            'text' => 'No',
                            'emoji' => true,
                        ],
                        'style' => 'danger',
                        'action_id' => "no_button_{$this->queryId}",
                        'value' => 'no',
                    ],
                ],
            ],
        ];
    }
}
