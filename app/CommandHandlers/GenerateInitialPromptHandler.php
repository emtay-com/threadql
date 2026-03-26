<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\GenerateInitialPromptCommand;
use App\Command\GenerateInitialPromptResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Command\DomainCommandResponse;
use App\Models\Query;
use App\Models\Tenant;
use App\Services\Llm\LlmProviderResolver;
use App\Services\Llm\PromptBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Handler for GenerateInitialPromptCommand
 */
class GenerateInitialPromptHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly LlmProviderResolver $providerResolver,
        private readonly PromptBuilder $promptBuilder
    ) {
    }

    /**
     * Handle the command to generate an initial prompt for a query
     */
    public function __invoke(GenerateInitialPromptCommand $command): DomainCommandResponse
    {
        // Load the query and tenant
        $query = Query::findOrFail($command->queryId);
        $tenant = Tenant::findOrFail($command->tenantId);

        // Resolve the LLM provider
        $provider = $this->providerResolver->resolve($tenant);

        // Get the model name
        $modelName = $this->providerResolver->getModelName($provider);

        // Build the prompt
        $messages = $this->promptBuilder->buildPrompt($query, $tenant);

        Log::info('Generated initial prompt', [
            'query_id' => $command->queryId,
            'tenant_id' => $command->tenantId,
            'provider' => $provider->adapter,
            'model' => $modelName,
            'message_count' => count($messages),
        ]);

        return new GenerateInitialPromptResponse(messages: $messages, provider: $provider, modelName: $modelName);
    }
}
