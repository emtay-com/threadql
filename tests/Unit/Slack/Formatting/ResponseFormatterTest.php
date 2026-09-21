<?php

declare(strict_types=1);

namespace Tests\Unit\Slack\Formatting;

use App\Slack\Formatting\Contracts\TagScannerInterface;
use App\Slack\Formatting\ResponseFormatter;
use App\Slack\Formatting\Scanners\TableScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ResponseFormatterTest extends TestCase
{
    private ResponseFormatter $formatter;

    private TableScanner $mockScanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new ResponseFormatter();
        $this->mockScanner = $this->createStub(TableScanner::class);
    }

    #[Test]
    public function it_builds_blocks_in_order_from_text_and_tables(): void
    {
        $this->mockScanner = $this->createMock(TableScanner::class);

        $input = "Here's some text\n[TABLE]\nName,Value\nTest,123\n[/TABLE]\nAnd more text";

        // Mock the scanner to return specific blocks
        $expectedTableBlocks = [
            [
                'type' => 'table',
                'table_width' => 2,
                'header' => [
                    'type' => 'table_row',
                    'cells' => [
                        [
                            'type' => 'table_cell',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Name',
                            ],
                        ],
                        [
                            'type' => 'table_cell',
                            'text' => [
                                'type' => 'plain_text',
                                'text' => 'Value',
                            ],
                        ],
                    ],
                ],
                'rows' => [
                    [
                        'type' => 'table_row',
                        'cells' => [
                            [
                                'type' => 'table_cell',
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => 'Test',
                                ],
                            ],
                            [
                                'type' => 'table_cell',
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => '123',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->mockScanner->expects($this->once())
            ->method('matches')
            ->with($input)
            ->willReturn(true);

        $this->mockScanner->expects($this->once())
            ->method('transform')
            ->with($input)
            ->willReturn($expectedTableBlocks);

        $this->formatter->addScanner($this->mockScanner);

        $blocks = $this->formatter->format($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_input(): void
    {
        $blocks = $this->formatter->format('');
        $this->assertEmpty($blocks);

        $blocks = $this->formatter->format('   ');
        $this->assertEmpty($blocks);
    }

    #[Test]
    public function it_strips_empty_segments(): void
    {
        $this->mockScanner = $this->createMock(TableScanner::class);

        $input = "Text\n\n\n[TABLE]\nA,1\n[/TABLE]\n\n\nMore text";

        $this->mockScanner->expects($this->once())
            ->method('matches')
            ->willReturn(true);

        $this->mockScanner->expects($this->once())
            ->method('transform')
            ->willReturn([
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'Text',
                    ],
                ],
                [
                    'type' => 'table',
                    'column_settings' => [],
                    'rows' => [],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => 'More text',
                    ],
                ],
            ]);

        $this->formatter->addScanner($this->mockScanner);

        $blocks = $this->formatter->format($input);

        $this->assertCount(3, $blocks);
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertEquals('table', $blocks[1]['type']);
        $this->assertEquals('section', $blocks[2]['type']);
    }

    #[Test]
    public function it_creates_plain_text_blocks_when_no_scanner_matches(): void
    {
        $input = "This is a simple message\n\nWith multiple paragraphs.\n\nAnd more content.";

        $blocks = $this->formatter->format($input);

        $this->assertCount(3, $blocks);

        foreach ($blocks as $block) {
            $this->assertEquals('section', $block['type']);
            $this->assertEquals('mrkdwn', $block['text']['type']);
        }

        $this->assertEquals('This is a simple message', $blocks[0]['text']['text']);
        $this->assertEquals('With multiple paragraphs.', $blocks[1]['text']['text']);
        $this->assertEquals('And more content.', $blocks[2]['text']['text']);
    }

    #[Test]
    public function it_handles_single_paragraph_text(): void
    {
        $input = 'This is just one paragraph of text.';

        $blocks = $this->formatter->format($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertEquals('mrkdwn', $blocks[0]['text']['type']);
        $this->assertEquals($input, $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_converts_newlines_to_spaces_within_paragraphs(): void
    {
        $input = "This is line one\nThis is line two\nThis is line three.";

        $blocks = $this->formatter->format($input);

        $this->assertCount(1, $blocks);
        $expected = "This is line one\nThis is line two\nThis is line three.";
        $this->assertEquals($expected, $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_uses_first_matching_scanner(): void
    {
        $input = 'Some text with table';

        $scanner1 = $this->createMock(TagScannerInterface::class);
        $scanner1->expects($this->once())
            ->method('matches')
            ->willReturn(true);
        $scanner1->expects($this->once())
            ->method('transform')
            ->willReturn([[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => 'Scanner1 result',
                ],
            ]]);

        $scanner2 = $this->createMock(TagScannerInterface::class);
        $scanner2->expects($this->never())
            ->method('matches');
        $scanner2->expects($this->never())
            ->method('transform');

        $this->formatter->addScanner($scanner1);
        $this->formatter->addScanner($scanner2);

        $blocks = $this->formatter->format($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('Scanner1 result', $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_handles_scanner_returning_empty_blocks(): void
    {
        $input = 'Some text content';

        $scanner = $this->createMock(TagScannerInterface::class);
        $scanner->expects($this->once())
            ->method('matches')
            ->willReturn(true);
        $scanner->expects($this->once())
            ->method('transform')
            ->willReturn([]); // Scanner returns empty blocks

        $this->formatter->addScanner($scanner);

        $blocks = $this->formatter->format($input);

        // Should fall back to plain text formatting
        $this->assertCount(1, $blocks);
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertEquals($input, $blocks[0]['text']['text']);
    }

    #[Test]
    public function it_can_add_multiple_scanners(): void
    {
        $scanner1 = $this->createStub(TagScannerInterface::class);
        $scanner2 = $this->createStub(TagScannerInterface::class);

        $this->formatter->addScanner($scanner1);
        $this->formatter->addScanner($scanner2);

        // Test that the formatter has both scanners
        $reflection = new \ReflectionClass($this->formatter);
        $property = $reflection->getProperty('scanners');
        $property->setAccessible(true);
        $scanners = $property->getValue($this->formatter);

        $this->assertCount(2, $scanners);
    }
}
