<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Slack;

use App\Infrastructure\Slack\SlackBlocksManipulator;
use Tests\TestCase;

final class SlackBlocksManipulatorTest extends TestCase
{
    public function test_filter_by_type_removes_matching_blocks(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section 1',
            ],
            [
                'type' => 'actions',
                'elements' => [],
            ],
            [
                'type' => 'section',
                'text' => 'Section 2',
            ],
            [
                'type' => 'divider',
            ],
        ];

        $result = SlackBlocksManipulator::filterByType($blocks, 'actions');

        $this->assertCount(3, $result);
        $this->assertEquals('section', $result[0]['type']);
        $this->assertEquals('section', $result[1]['type']);
        $this->assertEquals('divider', $result[2]['type']);
    }

    public function test_filter_by_type_returns_all_when_no_matches(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section 1',
            ],
            [
                'type' => 'section',
                'text' => 'Section 2',
            ],
        ];

        $result = SlackBlocksManipulator::filterByType($blocks, 'actions');

        $this->assertCount(2, $result);
    }

    public function test_filter_by_type_returns_empty_when_all_match(): void
    {
        $blocks = [
            [
                'type' => 'actions',
                'elements' => [],
            ],
            [
                'type' => 'actions',
                'elements' => [],
            ],
        ];

        $result = SlackBlocksManipulator::filterByType($blocks, 'actions');

        $this->assertEmpty($result);
    }

    public function test_filter_by_type_handles_blocks_without_type(): void
    {
        $blocks = [
            [
                'text' => 'No type',
            ],
            [
                'type' => 'section',
                'text' => 'Has type',
            ],
        ];

        $result = SlackBlocksManipulator::filterByType($blocks, 'section');

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('type', $result[0]);
    }

    public function test_filter_by_types_removes_multiple_types(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section',
            ],
            [
                'type' => 'actions',
                'elements' => [],
            ],
            [
                'type' => 'divider',
            ],
            [
                'type' => 'context',
                'elements' => [],
            ],
        ];

        $result = SlackBlocksManipulator::filterByTypes($blocks, ['actions', 'divider']);

        $this->assertCount(2, $result);
        $this->assertEquals('section', $result[0]['type']);
        $this->assertEquals('context', $result[1]['type']);
    }

    public function test_create_context_block_returns_correct_structure(): void
    {
        $result = SlackBlocksManipulator::createContextBlock('Test message');

        $this->assertEquals('context', $result['type']);
        $this->assertIsArray($result['elements']);
        $this->assertCount(1, $result['elements']);
        $this->assertEquals('mrkdwn', $result['elements'][0]['type']);
        $this->assertEquals('Test message', $result['elements'][0]['text']);
    }

    public function test_create_thank_you_block_returns_correct_message(): void
    {
        $result = SlackBlocksManipulator::createThankYouBlock();

        $this->assertEquals('context', $result['type']);
        $this->assertEquals('_Thanks for the feedback!_', $result['elements'][0]['text']);
    }

    public function test_append_block_adds_block_to_end(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'First',
            ],
            [
                'type' => 'section',
                'text' => 'Second',
            ],
        ];

        $newBlock = [
            'type' => 'divider',
        ];

        $result = SlackBlocksManipulator::appendBlock($blocks, $newBlock);

        $this->assertCount(3, $result);
        $this->assertEquals('divider', $result[2]['type']);
    }

    public function test_append_block_to_empty_array(): void
    {
        $blocks = [];
        $newBlock = [
            'type' => 'section',
            'text' => 'First',
        ];

        $result = SlackBlocksManipulator::appendBlock($blocks, $newBlock);

        $this->assertCount(1, $result);
        $this->assertEquals('section', $result[0]['type']);
    }

    public function test_replace_actions_with_thank_you(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Query result',
            ],
            [
                'type' => 'actions',
                'elements' => [
                    [
                        'type' => 'button',
                        'text' => 'Yes',
                    ],
                    [
                        'type' => 'button',
                        'text' => 'No',
                    ],
                ],
            ],
        ];

        $result = SlackBlocksManipulator::replaceActionsWithThankYou($blocks);

        $this->assertCount(2, $result);
        $this->assertEquals('section', $result[0]['type']);
        $this->assertEquals('context', $result[1]['type']);
        $this->assertStringContainsString('Thanks for the feedback!', $result[1]['elements'][0]['text']);
    }

    public function test_replace_actions_with_thank_you_when_no_actions(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Query result',
            ],
            [
                'type' => 'divider',
            ],
        ];

        $result = SlackBlocksManipulator::replaceActionsWithThankYou($blocks);

        $this->assertCount(3, $result);
        $this->assertEquals('section', $result[0]['type']);
        $this->assertEquals('divider', $result[1]['type']);
        $this->assertEquals('context', $result[2]['type']);
    }

    public function test_find_block_by_type_returns_first_match(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'First section',
            ],
            [
                'type' => 'divider',
            ],
            [
                'type' => 'section',
                'text' => 'Second section',
            ],
        ];

        $result = SlackBlocksManipulator::findBlockByType($blocks, 'section');

        $this->assertNotNull($result);
        $this->assertEquals('First section', $result['text']);
    }

    public function test_find_block_by_type_returns_null_when_not_found(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section',
            ],
            [
                'type' => 'divider',
            ],
        ];

        $result = SlackBlocksManipulator::findBlockByType($blocks, 'actions');

        $this->assertNull($result);
    }

    public function test_count_blocks_by_type_returns_correct_count(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section 1',
            ],
            [
                'type' => 'divider',
            ],
            [
                'type' => 'section',
                'text' => 'Section 2',
            ],
            [
                'type' => 'actions',
                'elements' => [],
            ],
            [
                'type' => 'section',
                'text' => 'Section 3',
            ],
        ];

        $result = SlackBlocksManipulator::countBlocksByType($blocks, 'section');

        $this->assertEquals(3, $result);
    }

    public function test_count_blocks_by_type_returns_zero_when_none_found(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Section',
            ],
            [
                'type' => 'divider',
            ],
        ];

        $result = SlackBlocksManipulator::countBlocksByType($blocks, 'actions');

        $this->assertEquals(0, $result);
    }

    public function test_filter_by_type_maintains_sequential_indices(): void
    {
        $blocks = [
            [
                'type' => 'section',
                'text' => 'Keep',
            ],
            [
                'type' => 'actions',
                'elements' => [],
            ],
            [
                'type' => 'section',
                'text' => 'Keep',
            ],
        ];

        $result = SlackBlocksManipulator::filterByType($blocks, 'actions');

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(2, $result);
    }
}
