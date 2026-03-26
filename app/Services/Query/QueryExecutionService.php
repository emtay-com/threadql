<?php

declare(strict_types=1);

namespace App\Services\Query;

use App\Enums\QueryStatus;
use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Llm\LlmFallbackExecutor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;

/**
 * Service for executing query workflow (LLM invocation and result processing)
 */
class QueryExecutionService
{
    public function __construct(
        private readonly ToolCallPersister $toolCallPersister,
        private readonly QueryStatusCalculator $statusCalculator,
        private readonly SlackMessageDispatcher $dispatcher,
        private readonly LlmFallbackExecutor $fallbackExecutor,
    ) {
    }

    /**
     * Execute the full query workflow
     *
     * @param array $config Configuration array with keys:
     *   - query_id: int
     *   - thread: Thread
     *   - query: Query
     *   - tenant: Tenant
     *   - prompt_data: array (provider, messages)
     *   - is_followup: bool
     *   - log_prefix: string
     * @return array{response: Response, status: QueryStatus, provider: mixed}
     */
    public function execute(array $config): array
    {
        $this->logStart($config);

        $fallbackResult = $this->fallbackExecutor->executeWithFallback(
            $config['tenant'],
            $config['prompt_data']['messages'],
            $config['prompt_data']['provider'],
            $config['query'],
        );
        $response = $fallbackResult['response'];
        $actualProvider = $fallbackResult['provider'];

        $this->processToolCalls($response, $config['query_id'], $config['tenant']->id);
        $finalStatus = $this->statusCalculator->calculateFinalStatus($config['query_id']);

        $this->updateQueryOutcome(
            $config['query'],
            $response,
            $actualProvider,
            $finalStatus,
            $config['is_followup'] ?? false
        );

        $this->logCompletionWithProvider($config, $finalStatus, $actualProvider);

        return [
            'response' => $response,
            'status' => $finalStatus,
            'provider' => $actualProvider,
        ];
    }

    /**
     * Check whether a CSV export was already delivered to Slack for this query
     */
    public function wasCsvAlreadyDelivered(int $queryId): bool
    {
        return $this->statusCalculator->wasCsvAlreadyDelivered($queryId);
    }

    /**
     * Post results to Slack
     */
    public function notifySlack(
        Thread $thread,
        string $responseText,
        int $queryId,
        string $defaultErrorMessage
    ): void {
        if (empty($responseText)) {
            $responseText = $defaultErrorMessage;
        }

        $this->dispatcher->dispatchFromAssistantText(
            $queryId,
            $thread->channel_id,
            $thread->thread_ts,
            $responseText
        );
    }

    /**
     * Process tool calls from response
     */
    private function processToolCalls(Response $response, int $queryId, int $tenantId): void
    {
        $toolCalls = $this->extractToolCallsFromResponse($response);
        $this->toolCallPersister->persistToolCallIds($toolCalls, $queryId);
        $this->toolCallPersister->createMissingToolCallRecords($toolCalls, $queryId, $tenantId);
    }

    /**
     * Update query with outcome and status
     */
    private function updateQueryOutcome(
        Query $query,
        Response $response,
        mixed $provider,
        QueryStatus $finalStatus,
        bool $isFollowup
    ): void {
        $query->refresh();

        $planJson = [
            'provider' => $provider->adapter,
            'model' => $provider->model_name ?: 'default',
            'timestamp' => Carbon::now()->toISOString(),
            'final_status' => $finalStatus->value,
        ];

        if ($isFollowup) {
            $planJson['is_followup'] = true;
        }

        $query->update([
            'status' => $finalStatus,
            'outcome' => $finalStatus === QueryStatus::DONE ? $response->text : null,
            'plan_json' => json_encode($planJson),
        ]);
    }

    /**
     * Log job start
     */
    private function logStart(array $config): void
    {
        $logPrefix = $config['log_prefix'] ?? 'query';

        Log::info("Starting {$logPrefix} job", [
            'tenant_id' => $config['tenant']->id,
            'thread_id' => $config['thread']->id,
            'query_id' => $config['query_id'],
            'channel_id' => $config['thread']->channel_id,
            'thread_ts' => $config['thread']->thread_ts,
            'current_status' => $config['query']->status,
        ]);
    }

    /**
     * Log job completion with the actual provider used
     */
    private function logCompletionWithProvider(array $config, QueryStatus $finalStatus, mixed $provider): void
    {
        $logPrefix = $config['log_prefix'] ?? 'query';

        Log::info("{$logPrefix} completed", [
            'tenant_id' => $config['tenant']->id,
            'thread_id' => $config['thread']->id,
            'query_id' => $config['query_id'],
            'provider' => $provider->adapter,
            'model' => $provider->model_name ?: 'default',
            'final_status' => $finalStatus->value,
        ]);
    }

    /**
     * Extract tool calls from Prism response
     *
     * @return \Prism\Prism\ValueObjects\ToolCall[]
     */
    private function extractToolCallsFromResponse(Response $response): array
    {
        return $response->steps->map(function (Step $step) {
            return $step->toolCalls;
        })->flatten()
            ->toArray();
    }
}
