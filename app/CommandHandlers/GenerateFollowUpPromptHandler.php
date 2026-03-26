<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\GenerateFollowUpPromptCommand;
use App\Command\GenerateFollowUpPromptResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Command\DomainCommandResponse;
use App\Models\Query;
use App\Models\Tenant;
use App\Services\Llm\FollowUpPromptBuilder;
use App\Services\Llm\LlmProviderResolver;
use Illuminate\Support\Facades\Log;

/**
 * Handler for GenerateFollowUpPromptCommand
 */
class GenerateFollowUpPromptHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly LlmProviderResolver $providerResolver,
        private readonly FollowUpPromptBuilder $followUpPromptBuilder
    ) {
    }

    /**
     * Handle the command to generate a follow-up prompt for a query
     */
    public function __invoke(GenerateFollowUpPromptCommand $command): DomainCommandResponse
    {
        // Load the query and tenant
        $query = Query::findOrFail($command->queryId);
        $tenant = Tenant::findOrFail($command->tenantId);

        // Resolve the LLM provider
        $provider = $this->providerResolver->resolve($tenant);

        // Get the model name
        $modelName = $this->providerResolver->getModelName($provider);

        // Build the follow-up prompt
        $messages = $this->followUpPromptBuilder->buildPrompt($query, $tenant);
        $lastSqlCall = $this->followUpPromptBuilder->findLastSuccessfulRunSqlQuery($query->thread_id);

        Log::info('Generated follow-up prompt', [
            'query_id' => $command->queryId,
            'tenant_id' => $command->tenantId,
            'provider' => $provider->adapter,
            'model' => $modelName,
            'message_count' => count($messages),
            'last_sql_call_id' => $lastSqlCall?->id,
        ]);

        return new GenerateFollowUpPromptResponse(
            messages: $messages,
            provider: $provider,
            modelName: $modelName,
            lastSqlCallId: $lastSqlCall?->id,
        );
    }
}
