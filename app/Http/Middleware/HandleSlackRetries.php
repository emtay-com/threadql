<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Immediately acknowledge Slack retry requests to prevent duplicate processing.
 *
 * Slack retries event deliveries when it doesn't receive a 200 response within
 * ~3 seconds, sending the same event_id with an X-Slack-Retry-Num header.
 * This middleware short-circuits retries to prevent race conditions that can
 * cause duplicate Query records.
 */
class HandleSlackRetries
{
    public function handle(Request $request, Closure $next): Response
    {
        $retryNum = $request->header('X-Slack-Retry-Num');

        if ($retryNum !== null) {
            $retryReason = $request->header('X-Slack-Retry-Reason', 'unknown');

            Log::info('Slack retry acknowledged, skipping duplicate processing', [
                'retry_num' => $retryNum,
                'retry_reason' => $retryReason,
                'event_id' => $request->input('event_id'),
            ]);

            return new JsonResponse([
                'ok' => true,
            ], Response::HTTP_OK);
        }

        return $next($request);
    }
}
