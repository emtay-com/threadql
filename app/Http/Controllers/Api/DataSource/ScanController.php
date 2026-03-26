<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataSource;

use App\Command\CrawlTableSchemaCommand;
use App\Http\Controllers\Controller;
use App\Http\Payloads\TableCollectionPayload;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Datasource;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class ScanController extends Controller
{
    public function __construct(
        private readonly DomainCommandBus $commandBus,
    ) {
    }

    /**
     * Scan a datasource to discover and extract table schemas.
     */
    public function __invoke(Tenant $tenant, Datasource $datasource): JsonResponse
    {
        if (Gate::denies('operate', [$datasource, $tenant])) {
            abort(404);
        }

        $command = new CrawlTableSchemaCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = $this->commandBus->dispatch($command);

        if (! $response->isSuccess()) {
            return new JsonResponse(
                [
                    'data' => [
                        'success' => false,
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

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
