<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack;

use App\Exceptions\EntityNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Slack\Actions\DefinitionRequestActionHandler;
use App\Http\Controllers\Slack\Actions\DefinitionSubmissionHandler;
use App\Http\Controllers\Slack\Actions\FeedbackActionHandler;
use App\Http\Controllers\Slack\Actions\PaginationActionHandler;
use App\Models\Tenant;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handle Slack interactive component events (button clicks and modal submissions)
 */
class SlackInteractiveController extends Controller
{
    public function __construct(
        private readonly DefinitionRequestActionHandler $definitionRequestHandler,
        private readonly DefinitionSubmissionHandler $definitionSubmissionHandler,
        private readonly FeedbackActionHandler $feedbackHandler,
        private readonly PaginationActionHandler $paginationHandler
    ) {
    }

    /**
     * Handle Slack interactive payload
     *
     * @param Request $request The HTTP request containing the Slack payload
     * @param Tenant $tenant The tenant context
     */
    public function handle(Request $request, Tenant $tenant): Response|JsonResponse
    {
        try {
            $payload = $this->parsePayload($request);
            $payloadType = $payload['type'] ?? '';

            return match ($payloadType) {
                'block_actions' => $this->handleBlockActions($payload, $tenant),
                'view_submission' => $this->handleViewSubmission($payload, $tenant),
                default => $this->handleUnknownPayloadType($payloadType),
            };
        } catch (EntityNotFoundException $e) {
            Log::warning('Entity not found during interactive handling', [
                'error' => $e->getMessage(),
            ]);

            return response()->noContent();
        } catch (Exception $e) {
            Log::error('Error processing Slack interactive payload', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->noContent();
        }
    }

    /**
     * Parse the Slack payload from form-encoded request
     *
     * @param Request $request The HTTP request
     * @return array The parsed payload
     */
    private function parsePayload(Request $request): array
    {
        $payloadString = $request->input('payload');

        if (! $payloadString) {
            throw new Exception('Missing payload parameter');
        }

        $payload = json_decode($payloadString, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in payload: '.json_last_error_msg());
        }

        return $payload;
    }

    /**
     * Handle block actions (button clicks)
     */
    private function handleBlockActions(array $payload, Tenant $tenant): Response|JsonResponse
    {
        if (empty($payload['actions'])) {
            Log::warning('No actions found in block_actions payload');

            return response()->noContent();
        }

        $action = $payload['actions'][0];
        $actionId = $action['action_id'] ?? '';
        $value = $action['value'] ?? '';
        $triggerId = $payload['trigger_id'] ?? '';

        // Route to appropriate handler based on action ID pattern
        if (preg_match('/^request_definition_(\d+)$/', $actionId, $matches)) {
            return $this->definitionRequestHandler->handle(
                (int) $matches[1],
                $value,
                $triggerId,
                $payload,
                $tenant
            );
        }

        if (preg_match('/^(yes|no)_button_(\d+)$/', $actionId, $matches)) {
            return $this->feedbackHandler->handle((int) $matches[2], $matches[1], $payload, $tenant);
        }

        if (preg_match('/^query_pagination_(?:prev_|next_)?(\d+)$/', $actionId, $matches)) {
            if ($value === '' || ! is_numeric($value)) {
                Log::warning('Pagination button clicked without numeric value');

                return new JsonResponse(null, Response::HTTP_BAD_REQUEST);
            }

            return $this->paginationHandler->handle((int) $matches[1], $value);
        }

        Log::warning('Unknown action_id format', [
            'action_id' => $actionId,
        ]);

        return response()->noContent();
    }

    /**
     * Handle view submission (modal form submission)
     */
    private function handleViewSubmission(array $payload, Tenant $tenant): JsonResponse
    {
        $callbackId = $payload['view']['callback_id'] ?? '';
        $userId = $payload['user']['id'] ?? '';
        $state = $payload['view']['state']['values'] ?? [];

        // Route to appropriate handler based on callback ID pattern
        if (preg_match('/^request_definition_modal_(\d+)$/', $callbackId, $matches)) {
            return $this->definitionSubmissionHandler->handle((int) $matches[1], $userId, $state);
        }

        Log::warning('Unknown view callback_id format', [
            'callback_id' => $callbackId,
        ]);

        return response()->json([
            'response_action' => 'clear',
        ]);
    }

    private function handleUnknownPayloadType(string $payloadType): Response
    {
        Log::info('Ignoring unknown payload type', [
            'type' => $payloadType,
        ]);

        return response()->noContent();
    }
}
