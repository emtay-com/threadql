<?php

declare(strict_types=1);

namespace App\Domain\Queries\Anchors;

use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Repositories\QueryAnchorService;

/**
 * Manages query anchors for Slack messages (create, update, hide).
 *
 * Anchors track Slack message timestamps so they can be updated in place
 * rather than posting new messages for pagination, table updates, etc.
 */
class QueryAnchorManager
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger,
        private readonly QueryAnchorService $anchorRepository
    ) {
    }

    /**
     * Upsert table anchor (create if not exists, update if exists).
     */
    public function upsertTableAnchor(Query $query, array $tablePayload): void
    {
        $anchor = $this->anchorRepository->getByQueryAndType($query->id, AnchorType::TABLE);
        $channelId = $query->thread->channel_id;

        if ($anchor) {
            $this->slackMessenger->updateMessageAttachments(
                $query->tenant,
                $channelId,
                $anchor->message_ts,
                'Query results (updated)',
                [$tablePayload]
            );
            $this->anchorRepository->updateAttachments($anchor, $tablePayload);
        } else {
            $result = $this->slackMessenger->replyInThreadAsAttachment(
                $query->tenant,
                $channelId,
                $query->thread->last_message_ts,
                'Query results',
                [$tablePayload]
            );

            if ($result !== null) {
                $this->anchorRepository->createForQuery(
                    $query,
                    AnchorType::TABLE,
                    $result['ts'],
                    null,
                    $tablePayload
                );
            }
        }
    }

    /**
     * Upsert pagination blocks anchor.
     */
    public function upsertPagingAnchor(Query $query, array $blocksPayload): void
    {
        $anchor = $this->anchorRepository->getByQueryAndType($query->id, AnchorType::PAGINATION_BLOCKS);
        $channelId = $query->thread->channel_id;

        if ($anchor) {
            $this->slackMessenger->updateMessageBlocks(
                $query->tenant,
                $channelId,
                $anchor->message_ts,
                $blocksPayload['text'],
                $blocksPayload['blocks']
            );
            $this->anchorRepository->updateBlocks($anchor, $blocksPayload['blocks']);
        } else {
            $result = $this->slackMessenger->replyInThreadWithBlocks(
                $query->tenant,
                $channelId,
                $query->thread->last_message_ts,
                $blocksPayload['text'],
                $blocksPayload['blocks']
            );

            if ($result !== null) {
                $this->anchorRepository->createForQuery(
                    $query,
                    AnchorType::PAGINATION_BLOCKS,
                    $result['ts'],
                    $blocksPayload['blocks']
                );
            }
        }
    }

    /**
     * Hide or clear pagination anchor if no longer needed.
     */
    public function hidePagingAnchor(Query $query): void
    {
        $anchor = $this->anchorRepository->getByQueryAndType($query->id, AnchorType::PAGINATION_BLOCKS);

        if ($anchor) {
            $emptyBlocks = $this->buildAllResultsShownBlocks();

            $this->slackMessenger->updateMessageBlocks(
                $query->tenant,
                $query->thread->channel_id,
                $anchor->message_ts,
                'All results shown',
                $emptyBlocks
            );
            $this->anchorRepository->updateBlocks($anchor, $emptyBlocks);
        }
    }

    /**
     * Build the "All results shown" blocks for hiding pagination.
     *
     * @return array<array<string, mixed>>
     */
    private function buildAllResultsShownBlocks(): array
    {
        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '_All results shown._',
                ],
            ],
        ];
    }
}
