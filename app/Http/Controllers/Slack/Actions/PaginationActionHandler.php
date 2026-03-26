<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack\Actions;

use App\Domain\Queries\Anchors\AnchorType;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\PaginateQueryJob;
use App\Models\Query;
use App\Repositories\QueryAnchorService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles pagination button actions
 */
class PaginationActionHandler
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger,
        private readonly QueryAnchorService $anchorService
    ) {
    }

    /**
     * Handle pagination button click
     */
    public function handle(int $queryId, string $value): Response
    {
        $this->logPaginationRequest($queryId, $value);

        $requestedOffset = (int) $value;
        $query = $this->loadAndValidateQuery($queryId);

        if (! $query) {
            return response()->noContent();
        }

        $currentOffset = $this->getCurrentOffset($query);

        $this->logPaginationDispatch($queryId, $requestedOffset, $currentOffset);

        $this->showWorkingOnItMessage($query);
        $this->dispatchPaginationJob($queryId, $requestedOffset, $currentOffset);

        return response()->noContent();
    }

    /**
     * Log the pagination request
     */
    private function logPaginationRequest(int $queryId, string $value): void
    {
        Log::info('Handling pagination button click ', [
            'query_id' => $queryId,
            'value' => $value,
        ]);
    }

    /**
     * Log the pagination job dispatch
     */
    private function logPaginationDispatch(int $queryId, int $requestedOffset, int $currentOffset): void
    {
        Log::info('Dispatching pagination job', [
            'query_id' => $queryId,
            'requested_offset' => $requestedOffset,
            'current_offset' => $currentOffset,
        ]);
    }

    /**
     * Load and validate the query
     */
    private function loadAndValidateQuery(int $queryId): ?Query
    {
        $query = Query::with(['thread', 'tenant'])->find($queryId);

        if (! $query || ! $query->thread || ! $query->tenant) {
            Log::warning('Pagination button clicked for invalid query', [
                'query_id' => $queryId,
            ]);

            return null;
        }

        if (! in_array($query->status, ['done', 'input_requested'])) {
            Log::warning('Pagination button clicked for query not in valid state', [
                'query_id' => $queryId,
                'status' => $query->status,
            ]);

            return null;
        }

        return $query;
    }

    /**
     * Get current offset from query metadata
     */
    private function getCurrentOffset(Query $query): int
    {
        $meta = $query->result_meta_json ?? [];

        return (int) ($meta['parameters']['offset'] ?? 0);
    }

    /**
     * Show "working on it" message in pagination controls
     */
    private function showWorkingOnItMessage(Query $query): void
    {
        try {
            $anchor = $this->getAnchorForWorkingMessage($query);

            if (! $anchor) {
                return;
            }

            $workingBlocks = $this->buildWorkingOnItBlocks();

            $this->updateWorkingMessage($query, $anchor, $workingBlocks);

        } catch (Exception $e) {
            $this->logWorkingMessageFailure($query, $e);
        }
    }

    private function getAnchorForWorkingMessage(Query $query): ?object
    {
        $anchor = $this->anchorService->getByQueryAndType($query->id, AnchorType::PAGINATION_BLOCKS);

        if (! $anchor) {
            Log::warning('No pagination controls anchor found for working message', [
                'query_id' => $query->id,
            ]);
        }

        return $anchor;
    }

    /**
     * Build working on it message blocks
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildWorkingOnItBlocks(): array
    {
        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '_Working on it…_',
                ],
            ],
        ];
    }

    /**
     * Update working message in Slack
     *
     * @param array<int, array<string, mixed>> $workingBlocks
     */
    private function updateWorkingMessage(Query $query, object $anchor, array $workingBlocks): void
    {
        $this->slackMessenger->updateMessageBlocks(
            $query->tenant,
            $query->thread->channel_id,
            $anchor->message_ts,
            'Working on it…',
            $workingBlocks
        );

        $this->anchorService->updateBlocks($anchor, $workingBlocks);
    }

    private function logWorkingMessageFailure(Query $query, Exception $exception): void
    {
        Log::warning('Failed to show working on it message', [
            'query_id' => $query->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Dispatch the pagination job
     */
    private function dispatchPaginationJob(int $queryId, int $requestedOffset, int $currentOffset): void
    {
        PaginateQueryJob::dispatch($queryId, $requestedOffset, $currentOffset)->onQueue('default');
    }
}
