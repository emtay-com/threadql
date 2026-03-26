<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Slack\SlackMessenger;
use App\Infrastructure\Slack\SlackUserResolver;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that ensures the Slack user is approved before processing the request.
 *
 * Extracts the Slack user ID from the request (events, commands, or interactive payloads),
 * resolves or creates the SlackUser, and blocks unapproved users with a notification.
 */
class EnsureSlackUserApproved
{
    private const UNAPPROVED_MESSAGE = 'Your account has not been approved yet. Please contact your workspace administrator to get access.';

    public function __construct(
        private readonly SlackUserResolver $slackUserResolver,
        private readonly SlackMessenger $slackMessenger,
    ) {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for URL verification challenges (no user involved)
        if ($request->input('type') === 'url_verification') {
            return $next($request);
        }

        $tenant = $request->route('tenant');
        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        $slackUserId = $this->extractSlackUserId($request);
        if (! $slackUserId) {
            return $next($request);
        }

        $slackUser = $this->slackUserResolver->findOrCreate($tenant, $slackUserId);

        if (! $slackUser->approved) {
            $this->notifyUnapprovedUser($tenant, $request, $slackUserId);

            Log::info('Blocked unapproved Slack user', [
                'tenant_id' => $tenant->id,
                'slack_user_id' => $slackUserId,
            ]);

            return new JsonResponse([
                'ok' => true,
            ], Response::HTTP_OK);
        }

        return $next($request);
    }

    /**
     * Extract the Slack user ID from the request based on the endpoint type.
     */
    private function extractSlackUserId(Request $request): ?string
    {
        // Events API: user is in event.user
        if ($request->has('event')) {
            $userId = $request->input('event.user');

            return is_string($userId) ? $userId : null;
        }

        // Interactive: user is in the payload JSON
        if ($request->has('payload')) {
            $payload = json_decode((string) $request->input('payload'), true);

            return $payload['user']['id'] ?? null;
        }

        // Slash commands: user_id in form data
        $userId = $request->input('user_id');

        return is_string($userId) ? $userId : null;
    }

    /**
     * Extract the channel ID from the request based on the endpoint type.
     */
    private function extractChannelId(Request $request): ?string
    {
        // Events API
        if ($request->has('event')) {
            return $request->input('event.channel');
        }

        // Interactive
        if ($request->has('payload')) {
            $payload = json_decode((string) $request->input('payload'), true);

            return $payload['channel']['id'] ?? null;
        }

        // Slash commands
        return $request->input('channel_id');
    }

    /**
     * Send an ephemeral notification to the unapproved user.
     */
    private function notifyUnapprovedUser(Tenant $tenant, Request $request, string $slackUserId): void
    {
        $channelId = $this->extractChannelId($request);
        if (! $channelId) {
            return;
        }

        try {
            $this->slackMessenger->sendEphemeral($tenant, $channelId, $slackUserId, self::UNAPPROVED_MESSAGE);
        } catch (\Throwable $e) {
            Log::error('Failed to send unapproved user notification', [
                'tenant_id' => $tenant->id,
                'slack_user_id' => $slackUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
