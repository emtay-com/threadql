<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Command\GenerateAppManifestCommand;
use App\Command\GenerateAppManifestResponse;
use App\Http\Controllers\Controller;
use App\Http\Payloads\ManifestPayload;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller for generating Slack App Manifest for a tenant.
 */
class ManifestController extends Controller
{
    public function __construct(
        private readonly DomainCommandBus $commandBus
    ) {
    }

    /**
     * Generate and return the Slack App Manifest for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $baseUrl = config('app.url');

        $command = new GenerateAppManifestCommand(
            tenantUuid: $tenant->uuid,
            baseUrl: $baseUrl,
            appName: $tenant->name,
            botDisplayName: $tenant->bot_name ?? $tenant->name,
        );

        /** @var GenerateAppManifestResponse $response */
        $response = $this->commandBus->dispatch($command);

        if (! $response->isSuccess()) {
            return new JsonResponse(
                [
                    'error' => implode(', ', $response->getErrors()),
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $payload = new ManifestPayload((string) $response->getResult());

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
