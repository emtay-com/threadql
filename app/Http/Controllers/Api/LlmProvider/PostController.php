<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\LlmProvider;

use App\Http\Controllers\Controller;
use App\Http\Payloads\LlmProviderPayload;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Create a new LLM provider for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'adapter' => 'required|string|max:255',
            'url' => 'nullable|string|max:255',
            'model_name' => 'required|string|max:255',
            'api_key' => 'nullable|string|max:1000',
            'options' => 'nullable|array',
            'options.*' => 'nullable|string|max:1000',
            'enabled' => 'sometimes|boolean',
        ]);

        $validated['tenant_id'] = $tenant->id;
        $validated['sort'] = ((int) ($tenant->llmProviders()->max('sort') ?? -1)) + 1;

        /** @var \App\Models\LlmProvider $provider */
        $provider = $tenant->llmProviders()
            ->create($validated);
        $provider->refresh();

        return response()->json(new LlmProviderPayload($provider), 201);
    }
}
