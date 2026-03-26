<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Command\ExecuteParameterizedSelectCommand;
use App\Command\ExecuteParameterizedSelectCommandResponse;
use App\Command\Results\SelectResult;
use App\Domain\Queries\Anchors\QueryAnchorManager;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Debug\SqlDebugEcho;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\PaginationControlsBuilder;
use App\Infrastructure\Slack\SlackTableAttachmentBuilder;
use App\Models\Query;
use App\Services\Sql\TotalCountEstimator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handle pagination requests for query results
 */
final class PaginateQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 30;

    #[Assignable]
    private DomainCommandBus $commandBus;

    #[Assignable]
    private PaginationControlsBuilder $paginationBuilder;

    #[Assignable]
    private SlackTableAttachmentBuilder $tableBuilder;

    #[Assignable]
    private QueryAnchorManager $anchorManager;

    #[Assignable]
    private TotalCountEstimator $totalCountEstimator;

    #[Assignable]
    private ?SqlDebugEcho $sqlDebugEcho = null;

    public function __construct(
        public int $queryId,
        public int $requestedOffset,
        public int $currentOffset
    ) {
    }

    public function handle(
        DomainCommandBus $commandBus,
        PaginationControlsBuilder $paginationBuilder,
        SlackTableAttachmentBuilder $tableBuilder,
        QueryAnchorManager $anchorManager,
        TotalCountEstimator $totalCountEstimator,
        ?SqlDebugEcho $sqlDebugEcho = null
    ): void {
        $this->assignParams(func_get_args());

        $query = $this->loadQueryWithTenantDatasource();
        $pagingConfig = $this->pagingConfig();
        $limit = $pagingConfig->pageSize;
        $total = $this->resolveTotal($query);
        $offset = $this->normalizeOffset($this->requestedOffset, $total, $limit);

        Log::info('PaginateQueryJob: Processing query', [
            'query_id' => $query->id,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $rows = $this->executePageQueryWithTiming($query, $limit, $offset);

        $tablePayload = $this->tableBuilder->build($rows->columns, $rows->rows);
        $blocksPayload = $this->paginationBuilder->build($query->id, $offset, $limit, $total);

        $this->anchorManager->upsertTableAnchor($query, $tablePayload);

        if ($this->shouldShowPaging($total)) {
            $this->anchorManager->upsertPagingAnchor($query, $blocksPayload);
        } else {
            $this->anchorManager->hidePagingAnchor($query);
        }
    }

    /**
     * Execute page query with timing and debug echo.
     */
    private function executePageQueryWithTiming(Query $query, int $limit, int $offset): SelectResult
    {
        $startTime = microtime(true);
        $result = $this->runSelectPage($query, $limit, $offset);
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        $this->sqlDebugEcho?->maybeSend(
            $query,
            $query->parameters ?? [],
            $query->sql_text,
            $durationMs,
            $result->rowCount,
            'database'
        );

        return $result;
    }

    private function resolveTotal(Query $query): int
    {
        $meta = $query->result_meta_json ?? [];
        $resultFromMeta = $meta['total_count'] ?? 0;

        if ($resultFromMeta) {
            return (int) $resultFromMeta;
        }

        $totalComputed = $this->computeTotalCount($query);
        if ($totalComputed) {
            $query->result_meta_json = array_merge($meta, [
                'total_count' => $totalComputed,
            ]);
            $query->save();
        }

        return $totalComputed;
    }

    /**
     * Load query with tenant and datasource relationships.
     */
    private function loadQueryWithTenantDatasource(): Query
    {
        $query = Query::with(['thread', 'tenant.datasources'])->find($this->queryId);

        if (! $query || ! $query->thread || ! $query->tenant || $query->tenant->datasources->isEmpty()) {
            throw new RuntimeException("Query {$this->queryId} or required relationships not found");
        }

        return $query;
    }

    /**
     * Get pagination configuration.
     */
    private function pagingConfig(): object
    {
        return (object) [
            'pageSize' => config('pagination.page_size', 25),
            'maxColumns' => config('pagination.max_columns', 20),
            'maxRowsPreview' => config('pagination.max_rows_preview', 25),
        ];
    }

    /**
     * Normalize offset to be within bounds and aligned to page boundaries.
     */
    private function normalizeOffset(int $requestedOffset, int $total, int $limit): int
    {
        $effectiveOffset = max(0, min($requestedOffset, $total - 1));

        return (int) (floor($effectiveOffset / $limit) * $limit);
    }

    /**
     * Run SELECT query for a specific page.
     */
    private function runSelectPage(Query $query, int $limit, int $offset): SelectResult
    {
        $parameters = $query->parameters ?? [];
        $parameters['offset'] = $offset;

        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: $query->sql_text,
            parameters: $parameters,
            rowLimit: $limit
        );
        /** @var ExecuteParameterizedSelectCommandResponse $response */
        $response = $this->commandBus->dispatch($command);

        if (! $response->isSuccess()) {
            throw new RuntimeException('Failed to execute SELECT query: '.implode(', ', $response->getErrors()));
        }

        return $response->getResult();
    }

    /**
     * Compute total count for the query.
     */
    private function computeTotalCount(Query $query): int
    {
        $parameters = $query->parameters ?? [];
        $datasource = $query->tenant->datasources->first();

        return (int) $this->totalCountEstimator->estimateTotalCount($query->sql_text, $parameters, $datasource);
    }

    /**
     * Check if pagination controls should be shown.
     */
    private function shouldShowPaging(int $total): bool
    {
        $threshold = config('pagination.no_pagination_threshold', 25);

        return $total > $threshold;
    }
}
