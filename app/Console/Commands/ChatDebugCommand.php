<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Command\GenerateInitialPromptCommand;
use App\Command\GenerateInitialPromptResponse;
use App\Enums\QueryStatus;
use App\Enums\ThreadStatus;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Llm\DebugPrismProviderMapper;
use App\Services\Llm\PrismProviderMapper;
use Exception;
use Illuminate\Console\Command;
use Prism\Prism\Text\Step;

class ChatDebugCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:debug
                            {--no-tools : Force a one-shot response without tools}
                            {--model= : Override the LLM model}
                            {--max-ddl-kb=200 : Cap DDL context size in KB}
                            {--raw : Dump exact wire data without prettification}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run interactive chat flow with full wire logging for debugging';

    /**
     * Execute the console command.
     */
    public function handle(DomainCommandBus $commandBus, PrismProviderMapper $prismMapper): int
    {
        // Create debug middleware and debug mapper
        $debugMapper = app(DebugPrismProviderMapper::class);
        $this->info('🐛 CHAT DEBUG MODE');
        $this->info('=================');
        $this->newLine();

        try {
            // Get tenant ID
            $tenantId = $this->ask('Enter tenant ID', '1');
            if (! is_numeric($tenantId)) {
                $this->error('❌ Tenant ID must be numeric');

                return self::FAILURE;
            }

            // Validate tenant exists
            $tenant = Tenant::find((int) $tenantId);
            if (! $tenant) {
                $this->error("❌ Tenant with ID {$tenantId} not found");

                return self::FAILURE;
            }

            // Get query text
            $queryText = $this->ask('Enter your query');
            if (empty(trim($queryText))) {
                $this->error('❌ Query text cannot be empty');

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("🔧 Processing query: \"{$queryText}\"");
            $this->info("🏢 Using tenant: {$tenant->name} (ID: {$tenant->id})");
            $this->newLine();

            // Create a temporary query record for processing
            $query = $this->createTemporaryQuery($tenant->id, $queryText);

            // Generate initial prompt
            $this->info('📝 Generating prompt...');
            $promptCommand = new GenerateInitialPromptCommand($query->id, $tenant->id);
            /** @var GenerateInitialPromptResponse $promptResponse */
            $promptResponse = $commandBus->dispatch($promptCommand);

            $provider = $promptResponse->provider;
            $messages = $promptResponse->messages;

            // Display prompt information
            $this->displayPromptInfo($messages, $provider);

            // Execute the chat with wire logging
            $this->executeChatFlowWithWireLogging($debugMapper, $provider, $messages);

            $this->newLine();
            $this->info('✅ Debug session completed successfully');

            return self::SUCCESS;

        } catch (Exception $e) {
            $this->error("❌ Error during debug session: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error('Stack trace:');
                $this->error($e->getTraceAsString());
            }

            return self::FAILURE;
        }
    }

    /**
     * Create a temporary query record for processing
     */
    private function createTemporaryQuery(int $tenantId, string $queryText): Query
    {
        // Create a temporary thread for debug mode
        $thread = Thread::create([
            'tenant_id' => $tenantId,
            'team_id' => 'debug-team',
            'channel_id' => 'debug-channel',
            'thread_ts' => now()
                ->timestamp.'.000000',
            'starter_user_id' => 'debug-user',
            'status' => ThreadStatus::ACTIVE->value,
            'last_message_ts' => now()
                ->timestamp.'.000000',
        ]);

        return Query::create([
            'tenant_id' => $tenantId,
            'thread_id' => $thread->id,
            'status' => QueryStatus::RECEIVED->value,
            'raw_text' => $queryText,
            'channel_id' => null, // Debug mode doesn't need a real channel
        ]);
    }

    /**
     * Display prompt composition information
     */
    private function displayPromptInfo(array $messages, $provider): void
    {
        $this->info('📋 PROMPT COMPOSITION');
        $this->info('=====================');

        foreach ($messages as $index => $message) {
            $role = $message['role'];
            $content = $message['content'];

            $this->line(($index + 1).". <comment>{$role}</comment>");
            $this->line('   '.$this->truncateContent($content, 200));
            $this->newLine();
        }

        $this->info('🤖 PROVIDER INFO');
        $this->info('================');
        $this->line("Adapter: <info>{$provider->adapter}</info>");
        $this->line("Model: <info>{$provider->model_name}</info>");
        $this->newLine();
    }

    /**
     * Execute the chat flow with wire logging
     */
    private function executeChatFlowWithWireLogging(
        DebugPrismProviderMapper $debugMapper,
        $provider,
        array $messages
    ): void {
        $this->info('🚀 EXECUTING CHAT FLOW WITH WIRE LOGGING');
        $this->info('=========================================');
        $this->newLine();

        $startTime = microtime(true);

        try {
            // Execute the request with debug logging
            $response = $debugMapper->generateTextWithDebug($provider, $messages);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->newLine();
            $this->info("📤 ASSISTANT RESPONSE ({$duration}ms)");
            $this->info('===============================');

            $calls = $response->steps->map(function (Step $step) {
                return $step->toolCalls;
            })->flatten()
                ->toArray();

            dd($calls);

        } catch (Exception $e) {
            $this->newLine();
            $this->error("❌ Chat execution failed: {$e->getMessage()}");

            if ($this->option('verbose')) {
                $this->error('Exception details:');
                $this->error($e->getTraceAsString());
            }
        }
    }

    /**
     * Truncate content for display
     */
    private function truncateContent(string $content, int $maxLength): string
    {
        if (strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength).'...';
    }
}
