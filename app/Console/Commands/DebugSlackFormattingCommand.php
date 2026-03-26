<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Models\Query;
use App\Slack\Formatting\ResponseFormatter;
use Illuminate\Console\Command;

/**
 * Console command to debug Slack formatting for query results
 */
class DebugSlackFormattingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'slack:debug-formatting
                            {query_id : The ID of the query to debug formatting for}
                            {--raw : Output raw outcome text instead of formatted blocks}
                            {--submit : Actually submit the formatted blocks to Slack API}';

    /**
     * The console command description.
     */
    protected $description = 'Debug Slack formatting for a query outcome - shows how the LLM outcome text would be formatted for Slack, optionally submits to API';

    /**
     * Execute the console command.
     */
    public function handle(ResponseFormatter $formatter, SlackMessageDispatcher $slackMessageDispatcher): int
    {
        $queryId = (int) $this->argument('query_id');

        // Find the query
        $query = Query::with(['thread', 'tenant'])->find($queryId);
        if (! $query) {
            $this->error("Query with ID {$queryId} not found.");

            return 1;
        }

        $this->info("Debugging Slack formatting for Query ID: {$queryId}");
        $this->line("Query: {$query->raw_text}");
        $this->line("Status: {$query->status}");
        $this->line("Tenant: {$query->tenant->name} (ID: {$query->tenant_id})");

        $this->line("Thread ID: {$query->thread_id}");
        $this->line("Channel: {$query->thread->channel_id}");

        $this->line('');

        // Check if the query has an outcome
        if ($query->outcome === null || $query->outcome === '') {
            $this->warn('Query has no outcome data.');

            return 1;
        }

        $outcome = $query->outcome;

        // If --raw flag is used, just output the raw outcome text
        if ($this->option('raw')) {
            $this->info('=== RAW OUTCOME TEXT ===');
            $this->line($outcome);

            return 0;
        }

        $this->info('=== OUTCOME TEXT INPUT ===');
        $this->line($outcome);
        $this->line('');

        // Format using ResponseFormatter
        $blocks = $formatter->format($outcome);

        $this->info('=== SLACK BLOCKS OUTPUT ===');
        $this->line('Generated '.count($blocks).' block(s)');
        $this->line('');

        // Output as pretty JSON
        $this->line(json_encode($blocks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line('');
        $this->info('=== BLOCK SUMMARY ===');

        foreach ($blocks as $index => $block) {
            $blockType = $block['type'] ?? 'unknown';
            $summary = 'Block '.($index + 1).": {$blockType}";

            if ($blockType === 'section' && isset($block['text']['text'])) {
                $text = $block['text']['text'];
                $summary .= ' - '.mb_substr($text, 0, 50).(mb_strlen($text) > 50 ? '...' : '');
            } elseif ($blockType === 'table') {
                $rowCount = count($block['rows'] ?? []);
                $colCount = count($block['column_settings'] ?? []);
                $summary .= " - {$rowCount} rows × {$colCount} columns";
            }

            $this->line($summary);
        }

        // If --submit flag is provided, actually send to Slack
        if ($this->option('submit')) {
            if (! $query->thread) {
                $this->error('Cannot submit to Slack: Query is not associated with a thread.');

                return 1;
            }

            $this->info('');
            $this->info('=== SUBMITTING TO SLACK ===');

            try {
                $result = $slackMessageDispatcher->dispatchBlocksSync(
                    $query->tenant,
                    $queryId,
                    $query->thread->channel_id,
                    $query->thread->thread_ts,
                    $blocks,
                );

                if ($result) {
                    $this->info('✅ Successfully submitted to Slack!');
                } else {
                    $this->error('❌ Failed to submit to Slack (no result returned)');

                    return 1;
                }
            } catch (\Exception $e) {

                $this->error('❌ Failed to submit to Slack: '.$e->getMessage());

                return 1;
            }
        }

        return 0;
    }
}
