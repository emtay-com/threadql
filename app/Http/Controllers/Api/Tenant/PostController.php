<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Payloads\TenantPayload;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Create a new tenant.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'timezone' => 'required|string|timezone',
            'slack_app_id' => 'nullable|string|max:255',
            'slack_client_id' => 'nullable|string|max:255',
            'slack_bot_token' => 'nullable|string|max:255',
            'slack_signing_secret' => 'nullable|string|max:255',
            'slack_verification_token' => 'nullable|string|max:255',
        ]);

        $tenant = Tenant::create($validated);

        return response()->json(new TenantPayload($tenant), 201);
    }
}
