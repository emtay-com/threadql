<?php

declare(strict_types=1);

namespace App\Services\Query;

use App\Exceptions\EntityNotFoundException;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;

/**
 * Service for loading query-related entities with validation
 */
class QueryEntityLoader
{
    /**
     * Load thread or throw exception
     */
    public function loadThread(int $threadId): Thread
    {
        $thread = Thread::find($threadId);

        if (! $thread) {
            throw new EntityNotFoundException('Thread', (string) $threadId);
        }

        return $thread;
    }

    /**
     * Load query or throw exception
     */
    public function loadQuery(int $queryId): Query
    {
        $query = Query::find($queryId);

        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }

    /**
     * Load tenant from query or throw exception
     */
    public function loadTenantFromQuery(Query $query): Tenant
    {
        $tenant = $query->thread->tenant ?? null;

        if (! $tenant) {
            throw new EntityNotFoundException('Tenant', 'via Query->Thread relationship');
        }

        return $tenant;
    }

    /**
     * Load all entities at once
     *
     * @return array{thread: Thread, query: Query, tenant: Tenant}
     */
    public function loadAllEntities(int $threadId, int $queryId): array
    {
        $thread = $this->loadThread($threadId);
        $query = $this->loadQuery($queryId);
        $tenant = $this->loadTenantFromQuery($query);

        return [
            'thread' => $thread,
            'query' => $query,
            'tenant' => $tenant,
        ];
    }
}
