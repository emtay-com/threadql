<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueryStatus;
use App\Exceptions\EntityNotFoundException;
use App\Exceptions\LlmProviderNotSetException;
use App\Infrastructure\Attributes\Assignable;
use App\Infrastructure\Jobs\JobParamAssigner;
use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Llm\LlmProviderResolver;
use App\Services\Llm\PrismProviderMapper;
use App\Services\Llm\PromptBuilder;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSqlFromQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use JobParamAssigner;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 30;

    #[Assignable]
    private LlmProviderResolver $providerResolver;

    #[Assignable]
    private PrismProviderMapper $prismMapper;

    #[Assignable]
    private PromptBuilder $promptBuilder;

    #[Assignable]
    private SlackMessageDispatcher $dispatcher;

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new FailOnUnrecoverableException];
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $threadId,
        private readonly int $queryId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(
        LlmProviderResolver $providerResolver,
        PrismProviderMapper $prismMapper,
        PromptBuilder $promptBuilder,
        SlackMessageDispatcher $dispatcher
    ): void {
        $this->assignParams(func_get_args());

        $thread = null;
        $query = null;
        $tenant = null;
        $tenantId = null;

        try {
            ['thread' => $thread, 'query' => $query, 'tenant' => $tenant, 'tenantId' => $tenantId] = $this->loadEntities();

            $this->logJobStart($tenant, $thread);

            ['provider' => $provider, 'modelName' => $modelName] = $this->resolveLlmProvider($tenant);

            $response = $this->generateSqlWithLlm($query, $tenant, $provider);

            $this->postToSlack($thread, $response->text, $this->queryId);

            $this->updateQuerySuccess($query, $response, $provider, $modelName);

            $this->logJobSuccess($tenant, $provider, $modelName);

        } catch (LlmProviderNotSetException $e) {
            SendNoLlmProviderNotificationJob::dispatch($this->queryId);
            throw $e;
        } catch (Exception $e) {
            $this->handleJobError($e, $tenantId, $tenant, $query, $thread);
        }
    }

    /**
     * Load and validate required entities.
     *
     * @return array{thread: Thread, query: Query, tenant: Tenant, tenantId: int}
     */
    private function loadEntities(): array
    {
        $thread = $this->findThreadOrFail($this->threadId);
        $query = $this->findQueryOrFail($this->queryId);
        $tenantId = config('app.tenant_id_default', 1);
        $tenant = $this->findTenantOrFail($tenantId);

        return compact('thread', 'query', 'tenant', 'tenantId');
    }

    /**
     * Log the start of the SQL generation job.
     */
    private function logJobStart(Tenant $tenant, Thread $thread): void
    {
        Log::info('Starting SQL generation job', [
            'tenant_id' => $tenant->id,
            'thread_id' => $this->threadId,
            'query_id' => $this->queryId,
            'channel_id' => $thread->channel_id,
            'thread_ts' => $thread->thread_ts,
        ]);
    }

    /**
     * Resolve LLM provider for the tenant.
     *
     * @return array{provider: mixed, modelName: string}
     */
    private function resolveLlmProvider(Tenant $tenant): array
    {
        $provider = $this->providerResolver->resolve($tenant);
        $modelName = $this->providerResolver->getModelName($provider);

        return compact('provider', 'modelName');
    }

    /**
     * Generate SQL using LLM.
     */
    private function generateSqlWithLlm(Query $query, Tenant $tenant, mixed $provider): mixed
    {
        $messages = $this->promptBuilder->buildPrompt($query, $tenant);

        return $this->prismMapper->generateText($provider, $messages);
    }

    /**
     * Update query with successful result.
     */
    private function updateQuerySuccess(Query $query, mixed $response, mixed $provider, string $modelName): void
    {
        $query->update([
            'status' => QueryStatus::DONE,
            'sql_text' => $response,
            'plan_json' => json_encode([
                'provider' => $provider->adapter,
                'model' => $modelName,
                'timestamp' => Carbon::now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Log successful completion of the job.
     */
    private function logJobSuccess(Tenant $tenant, mixed $provider, string $modelName): void
    {
        Log::info('SQL generation completed successfully', [
            'tenant_id' => $tenant->id,
            'thread_id' => $this->threadId,
            'query_id' => $this->queryId,
            'provider' => $provider->adapter,
            'model' => $modelName,
        ]);
    }

    /**
     * Handle job error including logging, query update, and Slack notification.
     */
    private function handleJobError(
        Exception $exception,
        ?int $tenantId,
        ?Tenant $tenant,
        ?Query $query,
        ?Thread $thread
    ): void {
        Log::error('SQL generation job failed', [
            'tenant_id' => $tenantId,
            'thread_id' => $this->threadId,
            'query_id' => $this->queryId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($query) {
            $query->update([
                'status' => QueryStatus::ERROR,
                'plan_json' => json_encode([
                    'error' => $exception->getMessage(),
                    'timestamp' => Carbon::now()->toISOString(),
                ]),
            ]);
        }

        if ($thread) {
            $this->postErrorToSlack($tenant, $thread, $exception);
        }

        throw $exception;
    }

    /**
     * Post the response to Slack.
     *
     * @param Thread $thread The thread to post to
     * @param string $response The response text
     * @param int $queryId The query ID
     */
    private function postToSlack(Thread $thread, string $response, int $queryId): void
    {
        if (empty($response)) {
            $response = "I apologize, but I couldn't generate a SQL query for your request. Please try rephrasing your question.";
        }

        $this->dispatcher->dispatchFromAssistantText($queryId, $thread->channel_id, $thread->thread_ts, $response);
    }

    /**
     * Post error message to Slack.
     *
     * @param Tenant|null $tenant The tenant to post as
     * @param Thread $thread The thread to post to
     * @param Exception $exception The exception that occurred
     */
    private function postErrorToSlack(?Tenant $tenant, Thread $thread, Exception $exception): void
    {
        if (! $tenant) {
            return;
        }

        $errorMessage = 'I encountered an error while processing your request. Please try again later.';

        $this->dispatcher->dispatchMessageSync($tenant, $thread->channel_id, $thread->thread_ts, $errorMessage);
    }

    /**
     * Find thread or throw EntityNotFoundException.
     */
    private function findThreadOrFail(int $threadId): Thread
    {
        $thread = Thread::find($threadId);
        if (! $thread) {
            throw new EntityNotFoundException('Thread', (string) $threadId);
        }

        return $thread;
    }

    /**
     * Find query or throw EntityNotFoundException.
     */
    private function findQueryOrFail(int $queryId): Query
    {
        $query = Query::find($queryId);
        if (! $query) {
            throw new EntityNotFoundException('Query', (string) $queryId);
        }

        return $query;
    }

    /**
     * Find tenant or throw EntityNotFoundException.
     */
    private function findTenantOrFail(int $tenantId): Tenant
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            throw new EntityNotFoundException('Tenant', (string) $tenantId);
        }

        return $tenant;
    }
}
