<?php

declare(strict_types=1);

namespace App\Slack\Formatting;

use App\Slack\Formatting\Contracts\TagScannerInterface;

/**
 * Orchestrates scanning and transformation of raw LLM text into Slack blocks
 */
final class ResponseFormatter
{
    /**
     * @param TagScannerInterface[] $scanners
     */
    public function __construct(
        private array $scanners = []
    ) {
    }

    /**
     * Add a scanner to the formatter
     */
    public function addScanner(TagScannerInterface $scanner): self
    {
        $this->scanners[] = $scanner;

        return $this;
    }

    /**
     * Format the given text into Slack blocks
     *
     * @return array<int, array<string, mixed>>
     */
    public function format(string $text): array
    {
        if (empty(trim($text))) {
            return [];
        }

        $blocks = [];

        // Try each scanner in order
        foreach ($this->scanners as $scanner) {
            if ($scanner->matches($text)) {
                $blocks = $scanner->transform($text);
                break; // Use the first matching scanner
            }
        }

        // If no scanner matched or scanner returned empty blocks, treat as plain text
        if (empty($blocks)) {
            $blocks = $this->createPlainTextBlocks($text);
        }

        // Remove any empty blocks and ensure proper structure
        $blocks = array_filter($blocks, fn ($block) => ! empty($block));
        $blocks = array_values($blocks); // Re-index array

        return $blocks;
    }

    /**
     * Create plain text section blocks for content without special tags
     *
     * @return array<int, array<string, mixed>>
     */
    private function createPlainTextBlocks(string $text): array
    {
        // Split text into paragraphs and create section blocks
        $paragraphs = array_filter(
            array_map('trim', explode("\n\n", $text)),
            fn ($paragraph) => ! empty($paragraph)
        );

        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            // Preserve single newlines within paragraphs for formatting
            // but don't convert them to spaces as they provide line breaks in markdown
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $paragraph,
                ],
            ];
        }

        return $blocks;
    }
}
