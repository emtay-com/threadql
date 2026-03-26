<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\LlmProvider;

use App\Http\Controllers\Controller;
use App\Http\Payloads\LlmProviderPayload;
use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PutController extends Controller
{
    /**
     * Update an LLM provider.
     */
    public function __invoke(Request $request, Tenant $tenant, LlmProvider $llmProvider): JsonResponse
    {
        if (Gate::denies('update', [$llmProvider, $tenant])) {
            abort(404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'adapter' => 'required|string|max:255',
            'url' => 'nullable|string|max:255',
            'model_name' => 'required|string|max:255',
            'api_key' => 'sometimes|nullable|string|max:1000',
            'options' => 'sometimes|nullable|array',
            'options.*' => 'nullable|string|max:1000',
            'enabled' => 'sometimes|boolean',
            'sort' => 'sometimes|integer|min:0',
        ]);

        $llmProvider->name = $validated['name'];
        $llmProvider->adapter = $validated['adapter'];
        $llmProvider->url = $validated['url'];
        $llmProvider->model_name = $validated['model_name'];

        // Update api_key only if provided in payload
        if (array_key_exists('api_key', $validated)) {
            $llmProvider->api_key = $validated['api_key'];
        }

        if (array_key_exists('options', $validated)) {
            $llmProvider->options = $validated['options'];
        }

        if (array_key_exists('enabled', $validated)) {
            $llmProvider->enabled = $validated['enabled'];
        }

        if (array_key_exists('sort', $validated)) {
            $llmProvider->sort = $validated['sort'];
        }

        $llmProvider->save();

        return response()->json(new LlmProviderPayload($llmProvider));
    }
}
