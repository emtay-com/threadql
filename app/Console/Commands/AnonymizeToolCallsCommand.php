<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ToolCall;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AnonymizeToolCallsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tool-calls:anonymize
                            {--days= : Override default retention period in days}
                            {--dry-run : Show what would be anonymized without making changes}
                            {--chunk=1000 : Process records in chunks of this size}
                            {--verbose : Show detailed progress information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Anonymize tool call request and response payloads that are older than the retention period';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $retentionDays = $this->option('days') ?? config('debugging.max_retention_tool_output', 7);
        $chunkSize = (int) ($this->option('chunk') ?: config('debugging.anonymization.chunk_size', 1000));
        $isDryRun = $this->option('dry-run');
        $isVerbose = $this->option('verbose');

        $cutoffDate = Carbon::now()->subDays($retentionDays);

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        $this->info(
            "🔧 Anonymizing tool calls older than: {$cutoffDate->format('Y-m-d H:i:s')} ({$retentionDays} days ago)"
        );
        $this->newLine();

        // Count records to be processed
        $query = ToolCall::where('created_at', '<', $cutoffDate)
            ->whereNull('anonymized_at');

        $totalRecords = $query->count();

        if ($totalRecords === 0) {
            $this->info('✅ No tool calls found that need anonymization');

            return self::SUCCESS;
        }

        $this->info("📊 Found {$totalRecords} tool call records to anonymize");

        if (! $isDryRun && ! $this->confirm("Do you want to proceed with anonymizing {$totalRecords} records?")) {
            $this->info('❌ Operation cancelled');

            return self::FAILURE;
        }

        $anonymizedCount = 0;
        $skippedCount = 0;

        if ($isVerbose) {
            $progressBar = $this->output->createProgressBar($totalRecords);
            $progressBar->start();
        }

        // Process in chunks to avoid memory issues
        $query->chunk($chunkSize, function ($toolCalls) use (
            &$anonymizedCount,
            &$skippedCount,
            $isDryRun,
            $isVerbose,
            &$progressBar
        ) {
            foreach ($toolCalls as $toolCall) {
                try {
                    if (! $isDryRun) {
                        $anonymizedContent = config('debugging.anonymization.anonymized_content', '/* anonymized */');
                        $toolCall->update([
                            'request_payload' => $anonymizedContent,
                            'response_payload' => $anonymizedContent,
                            'anonymized_at' => Carbon::now(),
                        ]);
                    }

                    $anonymizedCount++;

                    if ($isVerbose) {
                        $this->line("  Anonymized tool call ID: {$toolCall->id}", 'vv');
                    }
                } catch (\Exception $e) {
                    $skippedCount++;

                    if ($isVerbose) {
                        $this->error("  Failed to anonymize tool call ID: {$toolCall->id} - {$e->getMessage()}", 'vv');
                    }
                }

                if ($isVerbose) {
                    $progressBar?->advance();
                }
            }
        });

        if ($isVerbose) {
            $progressBar->finish();
            $this->newLine();
        }

        // Summary
        $this->newLine();
        $this->info('📈 Summary:');
        $this->line("  • Found: {$totalRecords}");
        $this->line("  • Anonymized: {$anonymizedCount}");
        $this->line("  • Skipped: {$skippedCount}");

        if ($skippedCount > 0) {
            $this->warn("⚠️  {$skippedCount} records could not be processed due to errors");
        }

        if ($isDryRun) {
            $this->info('✅ Dry run completed successfully');
        } else {
            $this->info('✅ Anonymization completed successfully');
        }

        return self::SUCCESS;
    }
}
