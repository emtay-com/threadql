<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\LlmProvider;

use App\Http\Controllers\Controller;
use App\Http\Payloads\LlmProviderCollectionPayload;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all LLM providers for a tenant.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $providers = $tenant->llmProviders()
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        /** @var array<int, \App\Models\LlmProvider> $providerItems */
        $providerItems = $providers->all();

        $payload = new LlmProviderCollectionPayload($providerItems);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
