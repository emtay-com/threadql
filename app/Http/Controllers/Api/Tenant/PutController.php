<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Payloads\TenantPayload;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PutController extends Controller
{
    /**
     * Update a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'timezone' => 'required|string|timezone',
            'slack_app_id' => 'sometimes|nullable|string|max:255',
            'slack_client_id' => 'sometimes|nullable|string|max:255',
            'slack_bot_token' => 'sometimes|nullable|string|max:255',
            'slack_signing_secret' => 'sometimes|nullable|string|max:255',
            'slack_verification_token' => 'sometimes|nullable|string|max:255',
        ]);

        $tenant->name = $validated['name'];
        $tenant->timezone = $validated['timezone'];

        // Update Slack fields only if provided in payload
        if (array_key_exists('slack_app_id', $validated)) {
            $tenant->slack_app_id = $validated['slack_app_id'];
        }

        if (array_key_exists('slack_client_id', $validated)) {
            $tenant->slack_client_id = $validated['slack_client_id'];
        }

        if (array_key_exists('slack_bot_token', $validated)) {
            $tenant->slack_bot_token = $validated['slack_bot_token'];
        }

        if (array_key_exists('slack_signing_secret', $validated)) {
            $tenant->slack_signing_secret = $validated['slack_signing_secret'];
        }

        if (array_key_exists('slack_verification_token', $validated)) {
            $tenant->slack_verification_token = $validated['slack_verification_token'];
        }

        $tenant->save();

        return response()->json(new TenantPayload($tenant));
    }
}
