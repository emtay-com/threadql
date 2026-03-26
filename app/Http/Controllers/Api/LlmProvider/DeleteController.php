<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\LlmProvider;

use App\Http\Controllers\Controller;
use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeleteController extends Controller
{
    /**
     * Delete an LLM provider.
     */
    public function __invoke(Tenant $tenant, LlmProvider $llmProvider): Response
    {
        if (Gate::denies('delete', [$llmProvider, $tenant])) {
            abort(404);
        }

        $llmProvider->delete();

        return response()->noContent();
    }
}
