<?php

declare(strict_types=1);

namespace App\Slack\Formatting\Contracts;

/**
 * Interface for scanning and transforming tagged content in LLM responses
 */
interface TagScannerInterface
{
    /**
     * Returns true if this text contains at least one tag this scanner can handle.
     */
    public function matches(string $text): bool;

    /**
     * Transform the input into a list of Slack blocks.
     * May split the input around recognized tags and return both transformed blocks and passthrough
     * text segments (as 'section' blocks). For composition simplicity, each scanner can focus on
     * its own tag and return an ordered list of blocks.
     *
     * @return array<int, array<string, mixed>> Slack blocks
     */
    public function transform(string $text): array;
}
