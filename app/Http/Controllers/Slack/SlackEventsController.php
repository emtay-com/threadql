<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack;

use App\Enums\SlackEventType;
use App\Http\Controllers\Controller;
use App\Infrastructure\Slack\SlackMessenger;
use App\Infrastructure\Slack\SlackUserResolver;
use App\Models\Tenant;
use App\Slack\Events\EventValidator;
use App\Slack\Events\FollowUpDetector;
use App\Slack\Events\QueryCreator;
use App\Slack\Events\QueryJobDispatcher;
use App\Slack\Events\ThreadManager;
use App\Support\Messages\FollowupResponseMessages;
use App\Support\Messages\InitialResponseMessages;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Handle Slack Events API webhooks
 */
class SlackEventsController extends Controller
{
    private const ALLOWED_SLACK_EVENT_TYPES = [SlackEventType::APP_MENTION->value, SlackEventType::MESSAGE->value];

    public function __construct(
        private readonly SlackMessenger $slackMessenger,
        private readonly SlackUserResolver $slackUserResolver,
        private readonly EventValidator $eventValidator,
        private readonly ThreadManager $threadManager,
        private readonly QueryCreator $queryCreator,
        private readonly FollowUpDetector $followUpDetector,
        private readonly QueryJobDispatcher $jobDispatcher
    ) {
    }

    /**
     * Handle Slack events webhook
     */
    public function handle(Request $request, Tenant $tenant): JsonResponse
    {
        $payload = $request->all();
        $type = $payload['type'] ?? null;

        Log::info('Slack event received', [
            'type' => $type,
            'event_id' => $payload['event_id'] ?? null,
            'tenant_id' => $tenant->id ?? 'null',
        ]);

        // Handle URL verification challenge
        if ($type === SlackEventType::URL_VERIFICATION->value) {
            return new JsonResponse([
                'challenge' => $payload['challenge'] ?? '',
            ], Response::HTTP_OK);
        }

        // Handle event callback
        if ($type === SlackEventType::EVENT_CALLBACK->value) {
            return $this->handleEventCallback($payload, $tenant);
        }

        Log::warning('Unknown Slack event type', [
            'type' => $type,
        ]);

        return new JsonResponse([
            'ok' => true,
        ], Response::HTTP_OK);
    }

    private function handleEventCallback(array $payload, Tenant $tenant): JsonResponse
    {
        $event = $payload['event'] ?? [];
        if (! $this->shouldProcessEvent($event)) {
            Log::info('Ignoring Slack event', [
                'event_type' => $event['type'] ?? null,
                'channel_type' => $event['channel_type'] ?? null,
                'subtype' => $event['subtype'] ?? null,
                'bot_id' => $event['bot_id'] ?? null,
            ]);

            return new JsonResponse([
                'ok' => true,
            ], Response::HTTP_OK);
        }

        try {
            return $this->processSlackEvent($payload, $event, $tenant);
        } catch (Exception $e) {
            Log::error('Error processing Slack event', [
                'event_id' => $payload['event_id'] ?? null,
                'event_type' => $event['type'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'ok' => false,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Process a Slack event that should create a query
     */
    private function processSlackEvent(array $payload, array $event, Tenant $tenant): JsonResponse
    {
        // Validate event
        $validation = $this->eventValidator->validateAppMention($payload, $event);
        if (! $validation['valid']) {
            return new JsonResponse([
                'ok' => false,
            ], Response::HTTP_BAD_REQUEST);
        }

        $eventData = $this->eventValidator->extractEventData($payload, $event);

        // Database operations in a transaction — keep it tight (no external API calls)
        $dbResult = DB::transaction(function () use ($tenant, $eventData) {
            $thread = $this->threadManager->findOrCreateThread(
                $tenant->id,
                $eventData['team_id'],
                $eventData['channel_id'],
                $eventData['thread_ts'],
                $eventData['user_id']
            );

            // Reject new messages while a query is already being processed in this thread
            if ($this->followUpDetector->hasInFlightQuery($thread)) {
                return [
                    'thread' => $thread,
                    'query_result' => [
                        'query' => null,
                        'duplicate' => false,
                    ],
                    'in_flight' => true,
                ];
            }

            $slackUser = $this->slackUserResolver->findOrCreate($tenant, $eventData['user_id']);

            $result = $this->queryCreator->createQuery($tenant->id, $thread->id, $slackUser->id, $eventData);

            return [
                'thread' => $thread,
                'query_result' => $result,
                'in_flight' => false,
            ];
        });

        $thread = $dbResult['thread'];
        $result = $dbResult['query_result'];

        // If a query is already in flight, notify user and bail out
        if ($dbResult['in_flight']) {
            $this->sendInFlightNotification($tenant, $eventData);

            Log::info('Query rejected: in-flight query exists for thread', [
                'event_id' => $eventData['event_id'],
                'thread_id' => $thread->id,
                'channel_id' => $eventData['channel_id'],
            ]);

            return new JsonResponse([
                'ok' => true,
            ], Response::HTTP_OK);
        }

        if ($result['duplicate']) {
            return new JsonResponse([
                'ok' => true,
            ], Response::HTTP_OK);
        }

        $query = $result['query'];

        // Detect if follow-up
        $isFollowUp = $this->followUpDetector->isFollowUp(
            $thread,
            $eventData['message_ts'],
            $eventData['thread_ts']
        );

        // Send immediate reply (outside transaction — external API call)
        $replyResult = $this->sendImmediateReply($tenant, $eventData, $isFollowUp);

        if ($replyResult) {
            $this->threadManager->updateLastMessageTs($thread, $replyResult['ts']);
        }

        // Dispatch job
        $jobType = $this->jobDispatcher->dispatch($thread->id, $query->id, $isFollowUp);

        $this->logSuccess($eventData, $thread, $query, $replyResult, $jobType, $isFollowUp);

        return new JsonResponse([
            'ok' => true,
        ], Response::HTTP_OK);
    }

    /**
     * Send immediate acknowledgment reply to Slack
     */
    private function sendImmediateReply(Tenant $tenant, array $eventData, bool $isFollowUp): ?array
    {
        $userHandle = "<@{$eventData['user_id']}>";
        $messageClass = $isFollowUp ? FollowupResponseMessages::class : InitialResponseMessages::class;
        $replyText = '_'.$messageClass::random($userHandle).'_';

        try {
            return $this->slackMessenger->replyInThread(
                $tenant,
                $eventData['channel_id'],
                $eventData['thread_ts'],
                $replyText
            );
        } catch (Throwable $e) {
            Log::error('Failed to send initial reply to Slack', [
                'channel_id' => $eventData['channel_id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send ephemeral notification that a query is already being processed
     */
    private function sendInFlightNotification(Tenant $tenant, array $eventData): void
    {
        try {
            $this->slackMessenger->sendEphemeral(
                $tenant,
                $eventData['channel_id'],
                $eventData['user_id'],
                "I'm still working on your previous query. Please wait for it to finish before sending another one.",
                $eventData['thread_ts']
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send in-flight notification', [
                'channel_id' => $eventData['channel_id'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Log successful processing
     */
    private function logSuccess(
        array $eventData,
        $thread,
        $query,
        ?array $replyResult,
        string $jobType,
        bool $isFollowUp
    ): void {
        Log::info('Successfully processed Slack event', [
            'event_id' => $eventData['event_id'],
            'thread_id' => $thread->id,
            'query_id' => $query->id,
            'reply_sent' => ! empty($replyResult),
            'job_type' => $jobType,
            'is_followup' => $isFollowUp,
        ]);
    }

    private function shouldProcessEvent(array $event): bool
    {
        $eventType = $event['type'] ?? null;

        if (! in_array($eventType, self::ALLOWED_SLACK_EVENT_TYPES, true)) {
            return false;
        }

        if ($eventType === SlackEventType::APP_MENTION->value) {
            return true;
        }

        if (($event['channel_type'] ?? null) !== 'im') {
            return false;
        }

        if (! empty($event['subtype'])) {
            return false;
        }

        if (! empty($event['bot_id']) || isset($event['bot_profile'])) {
            return false;
        }

        return ! empty($event['user']);
    }
}
