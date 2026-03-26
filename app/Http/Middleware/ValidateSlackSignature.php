<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Validate Slack request signatures
 */
class ValidateSlackSignature
{
    private const SIGNATURE_PREFIX = 'v0=';

    private const TIMESTAMP_TOLERANCE_DEFAULT = 300; // 5 minutes in seconds

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Skip validation in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        // Get tenant from route parameters
        $tenant = $request->route('tenant');
        if (! $tenant) {
            Log::error('No tenant found in route for Slack signature validation');

            return response('Unauthorized', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        $signature = $request->header('X-Slack-Signature');
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $body = $request->getContent();

        if (! $signature || ! $timestamp) {
            Log::warning('Missing Slack signature headers', [
                'has_signature' => ! empty($signature),
                'has_timestamp' => ! empty($timestamp),
                'tenant_id' => $tenant->id,
            ]);

            return response('Unauthorized', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        // Check timestamp (reject if too old)
        $tolerance = config('slack.event_verification.timestamp_tolerance', self::TIMESTAMP_TOLERANCE_DEFAULT);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            Log::warning('Slack request timestamp too old', [
                'timestamp' => $timestamp,
                'current_time' => time(),
                'difference' => abs(time() - (int) $timestamp),
                'tolerance' => $tolerance,
                'tenant_id' => $tenant->id,
            ]);

            return response('Request too old', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        // Verify signature using tenant's signing secret
        $signingSecret = $tenant->slack_signing_secret;
        if (! $signingSecret) {
            Log::error('Slack signing secret not configured for tenant', [
                'tenant_id' => $tenant->id,
            ]);

            return response('Unauthorized', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = self::SIGNATURE_PREFIX.hash_hmac('sha256', "v0:{$timestamp}:{$body}", $signingSecret);

        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid Slack signature', [
                'expected' => $expectedSignature,
                'received' => $signature,
                'tenant_id' => $tenant->id,
            ]);

            return response('Unauthorized', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
