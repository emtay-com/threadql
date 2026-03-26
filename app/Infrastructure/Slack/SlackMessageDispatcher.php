<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

use App\Jobs\SendSlackAttachments;
use App\Jobs\SendSlackBlocks;
use App\Models\Tenant;
use App\Slack\Formatting\ResponseFormatter;

/**
 * Slack Message Dispatcher
 *
 * Handles splitting assistant responses into separate messages for blocks vs tables,
 * with rate-limited dispatch via queue jobs.
 */
final class SlackMessageDispatcher
{
    public function __construct(
        private SlackMessenger $messenger,
        private ResponseFormatter $formatter
    ) {
    }

    /**
     * Dispatch assistant text by splitting into blocks and attachments
     *
     * @param int $queryId The query being answered
     * @param string $channelId Slack channel
     * @param string $threadTs Slack thread_ts
     * @param string $assistantText Raw assistant text (unformatted)
     */
    public function dispatchFromAssistantText(
        int $queryId,
        string $channelId,
        string $threadTs,
        string $assistantText
    ): void {
        $blocks = $this->formatter->format($assistantText);

        $this->dispatchBlocks($queryId, $channelId, $threadTs, $blocks);
    }

    /**
     * Lower-level API if you already built blocks.
     *
     * Rate limiting between messages is handled inside each job via
     * SlackChannelRateLimiter — no fixed delay is applied at dispatch time.
     *
     * @param int $queryId The query being answered
     * @param string $channelId Slack channel
     * @param string $threadTs Slack thread_ts
     * @param array<int, array<string,mixed>> $blocks
     */
    public function dispatchBlocks(int $queryId, string $channelId, string $threadTs, array $blocks): void
    {
        $chunks = $this->splitBlocksIntoChunks($blocks);

        foreach ($chunks as $chunk) {
            if ($chunk['kind'] === 'blocks') {
                SendSlackBlocks::dispatch(
                    $queryId,
                    $channelId,
                    $threadTs,
                    $chunk['fallbackText'],
                    $chunk['blocks']
                );
            } else { // 'attachment'
                SendSlackAttachments::dispatch(
                    $queryId,
                    $channelId,
                    $threadTs,
                    $chunk['fallbackText'],
                    [$chunk['attachment']]
                );
            }
        }
    }

    public function dispatchMessageSync(Tenant $tenant, string $channelId, string $threadTs, string $text): void
    {
        $blocks = new MarkdownBlocks($text)
            ->toArray();

        $this->messenger->replyInThreadWithBlocks($tenant, $channelId, $threadTs, $text, $blocks);
    }

    /**
     * Synchronous version for debugging - sends blocks immediately with sleep delays.
     * Useful for DebugSlackFormattingCommand to see results immediately.
     *
     * @param int $queryId The query being answered
     * @param string $channelId Slack channel
     * @param string $threadTs Slack thread_ts
     * @param array<int, array<string,mixed>> $blocks
     */
    public function dispatchBlocksSync(
        Tenant $tenant,
        int $queryId,
        string $channelId,
        string $threadTs,
        array $blocks
    ): bool {
        $chunks = $this->splitBlocksIntoChunks($blocks);

        foreach ($chunks as $chunk) {
            if ($chunk['kind'] === 'blocks') {
                $this->messenger->replyInThreadWithBlocks(
                    $tenant,
                    $channelId,
                    $threadTs,
                    $chunk['fallbackText'],
                    $chunk['blocks']
                );
            } else { // 'attachment'
                $this->messenger->replyInThreadAsAttachment(
                    $tenant,
                    $channelId,
                    $threadTs,
                    $chunk['fallbackText'],
                    [$chunk['attachment']]
                );
            }

            // Sleep for 2 seconds between messages to respect rate limits
            if (count($chunks) > 1) {
                sleep(2);
            }
        }

        return true;
    }

    /**
     * Split blocks into chunks of contiguous non-table blocks and table attachments
     *
     * @param array<int, array<string,mixed>> $blocks
     * @return array<int, array{kind: string, fallbackText: string, blocks?: array<int, array<string,mixed>>, attachment?: array<string,mixed>}>
     */
    private function splitBlocksIntoChunks(array $blocks): array
    {
        $chunks = [];
        $currentBlockChunk = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'table') {
                // Flush any accumulated blocks before the table
                if (! empty($currentBlockChunk)) {
                    $chunks[] = [
                        'kind' => 'blocks',
                        'fallbackText' => $this->extractTextFromBlocks($currentBlockChunk),
                        'blocks' => $currentBlockChunk,
                    ];
                    $currentBlockChunk = [];
                }

                // Create attachment with the table block
                $chunks[] = [
                    'kind' => 'attachment',
                    'fallbackText' => 'Results table',
                    'attachment' => [
                        'blocks' => [$block],
                    ],
                ];
            } else {
                // Accumulate non-table blocks
                $currentBlockChunk[] = $block;
            }
        }

        // Flush any remaining blocks
        if (! empty($currentBlockChunk)) {
            $chunks[] = [
                'kind' => 'blocks',
                'fallbackText' => $this->extractTextFromBlocks($currentBlockChunk),
                'blocks' => $currentBlockChunk,
            ];
        }

        return $chunks;
    }

    /**
     * Extract fallback text from blocks for use in message text field
     *
     * @param array<int, array<string,mixed>> $blocks
     */
    private function extractTextFromBlocks(array $blocks): string
    {
        $textParts = [];

        foreach ($blocks as $block) {
            if ($block['type'] === 'section' && isset($block['text']['text'])) {
                $textParts[] = $block['text']['text'];
            } elseif ($block['type'] === 'context' && isset($block['elements'])) {
                foreach ($block['elements'] as $element) {
                    if (isset($element['text'])) {
                        $textParts[] = $element['text'];
                    }
                }
            }
        }

        $text = implode(' ', $textParts);

        // Truncate to reasonable length for Slack text field
        return strlen($text) > 150 ? substr($text, 0, 147).'...' : $text;
    }
}
