<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataSource;

use App\Http\Controllers\Controller;
use App\Http\Payloads\DataSourceCollectionPayload;
use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all datasources for a tenant.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $datasources = $tenant->datasources()
            ->orderBy('created_at', 'desc')
            ->get();

        /** @var array<int, Datasource> $datasourceItems */
        $datasourceItems = $datasources->all();

        $payload = new DataSourceCollectionPayload($datasourceItems);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
