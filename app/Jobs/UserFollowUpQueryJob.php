<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Command\GenerateFollowUpPromptCommand;
use App\Command\GenerateFollowUpPromptResponse;
use App\Enums\QueryStatus;
use App\Enums\Queue;
use App\Exceptions\AllProvidersExhaustedException;
use App\Exceptions\DatasourceNotSetException;
use App\Exceptions\LlmProviderNotSetException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Jobs\Contracts\QueryJobContract;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Jobs\Middleware\QueryLifecycleMiddleware;
use App\Jobs\Support\QueryCacheKeyManager;
use App\Models\Tenant;
use App\Services\Query\QueryEntityLoader;
use App\Services\Query\QueryExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job to handle follow-up queries in existing Slack threads
 */
class UserFollowUpQueryJob implements ShouldQueue, QueryJobContract
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 1440;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new QueryLifecycleMiddleware(app(QueryCacheKeyManager::class)), new FailOnUnrecoverableException];
    }

    public function getQueryId(): int
    {
        return $this->queryId;
    }

    public function getThreadId(): int
    {
        return $this->threadId;
    }

    #[Assignable]
    private DomainCommandBus $commandBus;

    #[Assignable]
    private QueryEntityLoader $entityLoader;

    #[Assignable]
    private QueryExecutionService $executionService;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $threadId,
        private readonly int $queryId
    ) {
        $this->afterCommit = true;
        $this->onQueue(Queue::LONG_QUEUE->value);
    }

    /**
     * Execute the job.
     */
    public function handle(
        DomainCommandBus $commandBus,
        QueryEntityLoader $entityLoader,
        QueryExecutionService $executionService
    ): void {
        $this->assignParams(func_get_args());

        $entities = $this->entityLoader->loadAllEntities($this->threadId, $this->queryId);

        if (! $entities['tenant']->primaryDatasource()) {
            SendNoDatasourceNotificationJob::dispatch($this->queryId);
            throw new DatasourceNotSetException($entities['tenant']->id);
        }

        try {
            $promptData = $this->generateFollowUpPrompt($entities['tenant']);
        } catch (LlmProviderNotSetException $e) {
            SendNoLlmProviderNotificationJob::dispatch($this->queryId);
            throw $e;
        }

        $config = [
            'query_id' => $this->queryId,
            'thread' => $entities['thread'],
            'query' => $entities['query'],
            'tenant' => $entities['tenant'],
            'prompt_data' => $promptData,
            'is_followup' => true,
            'log_prefix' => 'Follow-up query',
        ];

        try {
            $result = $this->executionService->execute($config);
        } catch (AllProvidersExhaustedException $e) {
            Log::error('All LLM providers exhausted', [
                'query_id' => $this->queryId,
                'thread_id' => $this->threadId,
                'providers_tried' => array_map(fn ($p) => $p->name, $e->getProvidersTried()),
                'error' => $e->getMessage(),
            ]);
            $entities['query']->update([
                'status' => QueryStatus::ERROR,
            ]);
            $this->executionService->notifySlack(
                $entities['thread'],
                '',
                $this->queryId,
                'All configured LLM providers failed. Please contact your administrator.'
            );

            return;
        }

        // Notify Slack (skip if CSV was already delivered to the thread)
        try {
            if ($this->executionService->wasCsvAlreadyDelivered($this->queryId)) {
                Log::info('Skipping Slack notification - CSV already delivered', [
                    'query_id' => $this->queryId,
                ]);
                if ($result['status'] === QueryStatus::DONE) {
                    SendFeedbackSurveyJob::dispatch($this->queryId)->delay(5);
                }
            } else {
                $this->notifySlack($entities['thread'], $result['response']->text, $result['status']);
            }
        } catch (Throwable $e) {
            Log::error('Failed to post to Slack', [
                'query_id' => $this->queryId,
                'thread_id' => $this->threadId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate follow-up prompt using domain command
     */
    private function generateFollowUpPrompt(Tenant $tenant): array
    {
        $promptCommand = new GenerateFollowUpPromptCommand($this->queryId, $tenant->id);
        /** @var GenerateFollowUpPromptResponse $promptResponse */
        $promptResponse = $this->commandBus->dispatch($promptCommand);

        return [
            'provider' => $promptResponse->provider,
            'messages' => $promptResponse->messages,
        ];
    }

    /**
     * Notify Slack and dispatch follow-up jobs
     */
    private function notifySlack($thread, string $responseText, QueryStatus $finalStatus): void
    {
        $defaultErrorMessage = "I apologize, but I couldn't generate a SQL query for your follow-up request. Please try rephrasing your question.";

        $this->executionService->notifySlack($thread, $responseText, $this->queryId, $defaultErrorMessage);

        // Dispatch feedback survey with a fixed delay to ensure it appears after all
        // result messages (which are rate-limited to ~1.1 s apart by the jobs themselves).
        if ($finalStatus === QueryStatus::DONE) {
            SendFeedbackSurveyJob::dispatch($this->queryId)->delay(5);
        }
    }
}
