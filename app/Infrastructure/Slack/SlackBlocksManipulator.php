<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Utility class for manipulating Slack Block Kit blocks.
 * Provides common operations for filtering, creating, and modifying blocks.
 */
class SlackBlocksManipulator
{
    /**
     * Filter out blocks of a specific type
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function filterByType(array $blocks, string $typeToRemove): array
    {
        $filtered = array_filter($blocks, fn ($block) => ($block['type'] ?? '') !== $typeToRemove);

        return array_values($filtered);
    }

    /**
     * Remove multiple block types
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string> $typesToRemove
     * @return array<int, array<string, mixed>>
     */
    public static function filterByTypes(array $blocks, array $typesToRemove): array
    {
        $filtered = array_filter($blocks, fn ($block) => ! in_array($block['type'] ?? '', $typesToRemove, true));

        return array_values($filtered);
    }

    /**
     * Create a context block with markdown text
     *
     * @return array<string, mixed>
     */
    public static function createContextBlock(string $text): array
    {
        return [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => $text,
            ]],
        ];
    }

    /**
     * Create a "Thank you" context block
     *
     * @return array<string, mixed>
     */
    public static function createThankYouBlock(): array
    {
        return self::createContextBlock('_Thanks for the feedback!_');
    }

    /**
     * Add a block to the end of the blocks array
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param array<string, mixed> $blockToAdd
     * @return array<int, array<string, mixed>>
     */
    public static function appendBlock(array $blocks, array $blockToAdd): array
    {
        $blocks[] = $blockToAdd;

        return $blocks;
    }

    /**
     * Remove actions block and add thank you block
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    public static function replaceActionsWithThankYou(array $blocks): array
    {
        $filtered = self::filterByType($blocks, 'actions');

        return self::appendBlock($filtered, self::createThankYouBlock());
    }

    /**
     * Find first block of a specific type
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    public static function findBlockByType(array $blocks, string $type): ?array
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === $type) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Count blocks of a specific type
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public static function countBlocksByType(array $blocks, string $type): int
    {
        return count(array_filter($blocks, fn ($block) => ($block['type'] ?? '') === $type));
    }
}
