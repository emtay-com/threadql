<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Command\GenerateFollowUpPromptCommand;
use App\Command\GenerateFollowUpPromptResponse;
use App\Command\GenerateInitialPromptCommand;
use App\Command\GenerateInitialPromptResponse;
use App\Enums\QueryStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Query;
use Exception;
use Illuminate\Console\Command;

/**
 * Console command to replay a query and dump the prompt generation result
 */
class ReplayQueryCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'query:replay {query_id : The ID of the query to replay}';

    /**
     * The console command description.
     */
    protected $description = 'Replay a query and dump the prompt generation result for debugging';

    /**
     * Execute the console command.
     */
    public function handle(DomainCommandBus $commandBus): int
    {
        $queryId = (int) $this->argument('query_id');

        // Find the query
        $query = Query::find($queryId);
        if (! $query) {
            $this->error("Query with ID {$queryId} not found.");

            return 1;
        }

        $this->info("Replaying query ID: {$queryId}");
        $this->info("User query: {$query->raw_text}");
        $this->info("Tenant ID: {$query->tenant_id}");

        // Determine if this is a follow-up query
        $isFollowUp = $this->isFollowUpQuery($query);
        $this->info('Query type: '.($isFollowUp ? 'Follow-up' : 'Initial'));
        $this->line('');

        try {
            // Generate the prompt using the appropriate domain command
            if ($isFollowUp) {
                $promptCommand = new GenerateFollowUpPromptCommand($queryId, $query->tenant_id);
                $this->info('Using GenerateFollowUpPromptCommand');
                /** @var GenerateFollowUpPromptResponse $promptResponse */
                $promptResponse = $commandBus->dispatch($promptCommand);
            } else {
                $promptCommand = new GenerateInitialPromptCommand($queryId, $query->tenant_id);
                $this->info('Using GenerateInitialPromptCommand');
                /** @var GenerateInitialPromptResponse $promptResponse */
                $promptResponse = $commandBus->dispatch($promptCommand);
            }

            // Display the results
            $this->info('=== PROMPT GENERATION RESULT ===');
            $this->line('');

            $this->info('Provider: '.$promptResponse->provider->adapter);
            $this->info('Model: '.$promptResponse->modelName);
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

            $this->line('');
            $this->info('Prompt generation completed successfully!');

            return 0;

        } catch (Exception $e) {
            $this->error('Error generating prompt: '.$e->getMessage());
            $this->error('Stack trace: '.$e->getTraceAsString());

            return 1;
        }
    }

    /**
     * Determine if a query is a follow-up query
     */
    private function isFollowUpQuery(Query $query): bool
    {
        // If the query doesn't have a thread, it's not a follow-up
        if (! $query->thread_id) {
            return false;
        }

        // Check if the thread has at least one DONE query that was created before this query
        $hasEarlierDoneQuery = Query::where('thread_id', $query->thread_id)
            ->where('status', QueryStatus::DONE->value)
            ->where('created_at', '<', $query->created_at)
            ->exists();

        return $hasEarlierDoneQuery;
    }
}
