<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Command\GenerateInitialPromptCommand;
use App\Command\GenerateInitialPromptResponse;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Query;
use App\Services\Llm\PrismProviderMapper;
use Exception;
use Illuminate\Console\Command;

/**
 * Console command to debug LLM calls and show full interaction details
 */
class DebugLlmCallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'llm:debug {query_id : The ID of the query to debug} {--execute : Actually execute the LLM call and show response}';

    /**
     * The console command description.
     */
    protected $description = 'Debug LLM calls by showing the Prism builder configuration and optionally executing the call';

    /**
     * Execute the console command.
     */
    public function handle(DomainCommandBus $commandBus, PrismProviderMapper $prismMapper): int
    {
        $queryId = (int) $this->argument('query_id');
        $shouldExecute = $this->option('execute');

        // Find the query
        $query = Query::find($queryId);
        if (! $query) {
            $this->error("Query with ID {$queryId} not found.");

            return 1;
        }

        $this->info("Debugging LLM call for query ID: {$queryId}");
        $this->info("User query: {$query->raw_text}");
        $this->info("Tenant ID: {$query->tenant_id}");
        $this->line('');

        try {
            // Generate the prompt using the domain command
            $promptCommand = new GenerateInitialPromptCommand($queryId, $query->tenant_id);
            /** @var GenerateInitialPromptResponse $promptResponse */
            $promptResponse = $commandBus->dispatch($promptCommand);

            // Display the prompt generation results
            $this->info('=== PROMPT GENERATION RESULT ===');
            $this->line('');

            $this->info('Provider: '.$promptResponse->provider->adapter);
            $this->info('Model: '.$promptResponse->modelName);
            $this->info('Provider URL: '.($promptResponse->provider->url ?? 'default'));
            $this->line('');

            $this->info('=== MESSAGES ===');
            foreach ($promptResponse->messages as $index => $message) {
                $this->line('');
                $this->comment('Message '.($index + 1)." ({$message['role']}):");
                $this->line(str_repeat('-', 50));
                if ($message['content']) {
                    $this->line($message['content']);
                }

                if (isset($message['tool_calls'])) {
                    $this->comment('tool calls');
                    $this->line(json_encode($message['tool_calls']));
                }

                $this->line(str_repeat('-', 50));
            }

            // Create the Prism builder and show configuration
            $this->line('');
            $this->info('=== PRISM BUILDER CONFIGURATION ===');
            $this->line('');

            $builder = $prismMapper->makePrismBuilder($promptResponse->provider, $promptResponse->messages);

            $response = $builder->asText();

            $this->line('');
            $this->info('LLM debugging completed successfully!');
            dump($response);

            return 0;
        } catch (Exception $e) {
            $this->error('Error during LLM debugging: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return 1;
        }
    }
}
