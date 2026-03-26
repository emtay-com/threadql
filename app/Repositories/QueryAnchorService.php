<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Queries\Anchors\AnchorType;
use App\Models\Query;
use App\Models\QueryAnchor;

class QueryAnchorService
{
    /**
     * Get an anchor by query ID and type.
     */
    public function getByQueryAndType(int $queryId, AnchorType $type): ?QueryAnchor
    {
        return QueryAnchor::where('query_id', $queryId)
            ->where('type', $type)
            ->first();
    }

    /**
     * Create a new anchor for a query.
     */
    public function createForQuery(
        Query $query,
        AnchorType $type,
        string $messageTs,
        ?array $blocks = null,
        ?array $attachments = null
    ): QueryAnchor {
        return QueryAnchor::create([
            'query_id' => $query->id,
            'type' => $type,
            'message_ts' => $messageTs,
            'blocks_json' => $blocks,
            'attachments_json' => $attachments,
        ]);
    }

    /**
     * Update the blocks for an anchor.
     */
    public function updateBlocks(QueryAnchor $anchor, array $blocks): QueryAnchor
    {
        $anchor->update([
            'blocks_json' => $blocks,
        ]);

        return $anchor->fresh();
    }

    /**
     * Update the attachments for an anchor.
     */
    public function updateAttachments(QueryAnchor $anchor, array $attachments): QueryAnchor
    {
        $anchor->update([
            'attachments_json' => $attachments,
        ]);

        return $anchor->fresh();
    }
}
