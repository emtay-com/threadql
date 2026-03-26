<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Builder for pagination controls with Previous/Next buttons
 */
class PaginationControlsBuilder
{
    /**
     * Build pagination controls for a query
     *
     * @param int $offset Current page's offset
     * @param int $limit Page size
     * @param int $total Total rows
     * @return array{blocks: array<mixed>, text: string} Slack blocks + fallback text
     */
    public function build(int $queryId, int $offset, int $limit, int $total): array
    {
        $showingFrom = $offset + 1;
        $showingTo = min($offset + $limit, $total);

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Total rows:* {$total}, *showing* {$showingFrom}–{$showingTo}",
                ],
            ],
        ];

        $buttons = [];

        // Previous button (only if not at start)
        if ($offset > 0) {
            $buttons[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => '« Previous',
                    'emoji' => false,
                ],
                'style' => 'primary',
                'action_id' => "query_pagination_prev_{$queryId}",
                'value' => (string) max(0, $offset - $limit),
            ];
        }

        // Next/More results button (only if there are more results)
        if ($offset + $limit < $total) {
            $buttons[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'More results »',
                    'emoji' => false,
                ],
                'style' => 'primary',
                'action_id' => "query_pagination_next_{$queryId}",
                'value' => (string) ($offset + $limit),
            ];
        }

        // Add actions block if there are buttons
        if (! empty($buttons)) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $buttons,
            ];
        }

        return [
            'blocks' => $blocks,
            'text' => "Total rows: {$total}, showing {$showingFrom}–{$showingTo}",
        ];
    }
}
