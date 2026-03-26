<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\LlmProvider;

use App\Command\TestLlmProviderConnectionCommand;
use App\Http\Controllers\Controller;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\LlmProvider;
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
     * Test an LLM provider connection by pinging its models endpoint.
     */
    public function __invoke(Tenant $tenant, LlmProvider $llmProvider): JsonResponse
    {
        if (Gate::denies('operate', [$llmProvider, $tenant])) {
            abort(404);
        }

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $llmProvider->id);

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
                    'error' => $response->getResult()['error'] ?? 'Connection failed',
                ],
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
