<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Infrastructure\Slack\SlackClientFactory;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TestSlackController extends Controller
{
    public function __construct(
        private readonly SlackClientFactory $slackFactory
    ) {
    }

    /**
     * Test a tenant's Slack API integration.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        if (! $tenant->slack_bot_token) {
            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'No bot token configured for this tenant',
                ],
            ], 422);
        }

        try {
            $client = $this->slackFactory->create($tenant->slack_bot_token);
            $response = $client->authTest();

            if ($response->getOk()) {
                return response()->json([
                    'data' => [
                        'success' => true,
                        'message' => 'Slack connection verified',
                        'team' => $response->getTeam(),
                        'team_id' => $response->getTeamId(),
                        'user' => $response->getUser(),
                        'user_id' => $response->getUserId(),
                        'bot_id' => $response->getBotId(),
                    ],
                ]);
            }

            $error = method_exists($response, 'getError')
                ? ($response->getError() ?? 'Unknown error')
                : 'Unknown error';

            Log::warning('Slack auth test failed', [
                'tenant_id' => $tenant->id,
                'error' => $error,
            ]);

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Slack auth test failed: '.$error,
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Slack auth test exception', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'data' => [
                    'success' => false,
                    'message' => 'Slack auth test failed: '.$e->getMessage(),
                ],
            ], 422);
        }
    }
}
