<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Table;

use App\Http\Controllers\Controller;
use App\Http\Payloads\TableCollectionPayload;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all tables for a tenant.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $tables = Table::query()
            ->where('tenant_id', $tenant->id)
            ->withTrashed()
            ->orderBy('priority', 'desc')
            ->orderBy('name', 'asc')
            ->get();

        /** @var array<int, Table> $tableItems */
        $tableItems = $tables->all();

        $payload = new TableCollectionPayload($tableItems);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
