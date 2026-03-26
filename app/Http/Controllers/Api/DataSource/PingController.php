<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataSource;

use App\Command\TestDatasourceConnectionCommand;
use App\Http\Controllers\Controller;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PingController extends Controller
{
    public function __construct(
        private readonly DomainCommandBus $commandBus,
    ) {
    }

    /**
     * Test a datasource connection by pinging it with SELECT 1.
     */
    public function __invoke(Tenant $tenant, Datasource $datasource): JsonResponse
    {
        if (Gate::denies('operate', [$datasource, $tenant])) {
            abort(404);
        }

        $command = new TestDatasourceConnectionCommand(tenantId: $tenant->id, datasourceId: $datasource->id);

        $response = $this->commandBus->dispatch($command);

        if ($response->isSuccess()) {
            return new JsonResponse(
                [
                    'data' => [
                        'connected' => true,
                    ],
                ],
                Response::HTTP_OK,
            );
        }

        return new JsonResponse(
            [
                'data' => [
                    'connected' => false,
                ],
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
