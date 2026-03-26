<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack\Actions;

use App\Command\CreateDefinitionCommand;
use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Slack\DefinitionValidator;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\UserQueryInvokerJob;
use App\Models\Query;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Handles definition modal submission
 */
class DefinitionSubmissionHandler
{
    public function __construct(
        private readonly DomainCommandBus $commandBus,
        private readonly SlackMessenger $slackMessenger,
        private readonly DefinitionValidator $validator
    ) {
    }

    /**
     * Handle the definition submission
     */
    public function handle(int $queryId, string $userId, array $state): JsonResponse
    {
        $validation = $this->validator->validate($state);

        if ($this->validator->hasErrors($validation)) {
            return $this->buildErrorResponse($validation['errors']);
        }

        try {
            $query = $this->findQueryOrFail($queryId);
            $normalizedSubject = strtolower($validation['subject']);

            $response = $this->createDefinition($query, $userId, $normalizedSubject, $validation['definition']);

            $this->sendAcknowledgment($query, $response->isSuccess());
            $this->redispatchJobIfSuccessful($query, $response->isSuccess());

            $this->logResult($queryId, $normalizedSubject, $userId, $response->isSuccess());

            return $this->buildSuccessResponse();
        } catch (EntityNotFoundException $e) {
            Log::error('Query not found during definition submission', [
                'query_id' => $queryId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->buildErrorResponse([
                'definition_block' => 'An error occurred while saving the definition',
            ]);
        } catch (Exception $e) {
            Log::error('Error processing definition submission', [
                'query_id' => $queryId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->buildErrorResponse([
                'definition_block' => 'An error occurred while saving the definition',
            ]);
        }
    }

    /**
     * Find query or throw exception
     */
    private function findQueryOrFail(int $queryId): Query
    {
        $query = Query::with('thread')->find($queryId);

        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }

    /**
     * Create the definition via command bus
     */
    private function createDefinition(Query $query, string $userId, string $subject, string $definition)
    {
        $command = new CreateDefinitionCommand(
            tenantId: $query->tenant_id,
            userId: $userId,
            threadId: $query->thread_id,
            subject: $subject,
            definition: $definition,
            priority: 0
        );

        return $this->commandBus->dispatch($command);
    }

    /**
     * Send acknowledgment message to Slack
     */
    private function sendAcknowledgment(Query $query, bool $isSuccess): void
    {
        $message = $isSuccess
            ? 'Got it, thanks.'
            : 'That definition already exists.';

        $this->slackMessenger->replyInThread(
            $query->tenant,
            $query->thread->channel_id,
            $query->thread->last_message_ts,
            $message
        );
    }

    /**
     * Re-dispatch the query job if definition was created successfully
     */
    private function redispatchJobIfSuccessful(Query $query, bool $isSuccess): void
    {
        if (! $isSuccess) {
            return;
        }

        UserQueryInvokerJob::dispatch($query->thread_id, $query->id);

        Log::info('Re-dispatched UserQueryInvokerJob after definition creation', [
            'query_id' => $query->id,
            'thread_id' => $query->thread_id,
        ]);
    }

    /**
     * Log the result
     */
    private function logResult(int $queryId, string $subject, string $userId, bool $isSuccess): void
    {
        Log::info('Definition creation processed', [
            'query_id' => $queryId,
            'subject' => $subject,
            'user_id' => $userId,
            'is_success' => $isSuccess,
            'job_redispatched' => $isSuccess,
        ]);
    }

    /**
     * Build error response for Slack
     */
    private function buildErrorResponse(array $errors): JsonResponse
    {
        return response()->json([
            'response_action' => 'errors',
            'errors' => $errors,
        ]);
    }

    /**
     * Build success response for Slack
     */
    private function buildSuccessResponse(): JsonResponse
    {
        return response()->json([
            'response_action' => 'clear',
        ]);
    }
}
