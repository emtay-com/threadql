<?php

declare(strict_types=1);

namespace Tests\Unit\Slack\Formatting;

use App\Slack\Formatting\ResponseFormatter;
use Tests\TestCase;

final class IntegrationTest extends TestCase
{
    private ResponseFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = app(ResponseFormatter::class);
    }

    public function test_response_formatter_is_properly_injected(): void
    {
        $this->assertInstanceOf(ResponseFormatter::class, $this->formatter);
    }

    public function test_full_integration_with_table_formatting(): void
    {
        $input = "Here are the quarterly sales figures:\n\n[TABLE]\nQuarter,Revenue,Growth\nQ1 2024,\"$1,200,000\",+\nQ2 2024,\"$1,350,000\",\"12.5%\"\nQ3 2024,\"$1,420,000\",\"5.2%\"\nQ4 2024,\"$1,550,000\",\"9.4%\"\n[/TABLE]\n\nThese numbers show consistent growth throughout the year.";

        $blocks = $this->formatter->format($input);

        // Should have 3 blocks: section, table, section
        $this->assertCount(3, $blocks);

        // First block should be section with text before table
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertStringContainsString('quarterly sales figures', $blocks[0]['text']['text']);

        // Second block should be the table
        $this->assertEquals('table', $blocks[1]['type']);
        $this->assertCount(3, $blocks[1]['column_settings']); // 3 columns

        // Check table structure - first row is headers
        $this->assertCount(5, $blocks[1]['rows']); // Headers + 4 data rows
        $headerRow = $blocks[1]['rows'][0];
        $this->assertCount(3, $headerRow);
        $this->assertEquals('Quarter', $headerRow[0]['text']);
        $this->assertEquals('Revenue', $headerRow[1]['text']);
        $this->assertEquals('Growth', $headerRow[2]['text']);

        // Check first data row
        $firstDataRow = $blocks[1]['rows'][1];
        $this->assertCount(3, $firstDataRow);
        $this->assertEquals('Q1 2024', $firstDataRow[0]['text']);
        $this->assertEquals('$1,200,000', $firstDataRow[1]['text']);

        // Third block should be section with text after table
        $this->assertEquals('section', $blocks[2]['type']);
        $this->assertStringContainsString('consistent growth', $blocks[2]['text']['text']);
    }

    public function test_integration_with_multiple_tables(): void
    {
        $input = "Department A results:\n[TABLE]\nMetric,Value\nUsers,1500\nRevenue,$45,000\n[/TABLE]\n\nDepartment B results:\n[TABLE]\nMetric,Value\nUsers,2100\nRevenue,$62,000\n[/TABLE]";

        $blocks = $this->formatter->format($input);

        // Should have 4 blocks: section, table, section, table
        $this->assertCount(4, $blocks);

        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertEquals('table', $blocks[1]['type']);
        $this->assertCount(3, $blocks[1]['rows']); // Headers + 2 data rows
        $this->assertEquals('section', $blocks[2]['type']);
        $this->assertEquals('table', $blocks[3]['type']);
        $this->assertCount(3, $blocks[3]['rows']); // Headers + 2 data rows
    }

    public function test_integration_with_plain_text_only(): void
    {
        $input = "This is a simple message with multiple paragraphs.\n\nIt has some content here.\n\nAnd even more content in another paragraph.";

        $blocks = $this->formatter->format($input);

        $this->assertCount(3, $blocks);

        foreach ($blocks as $block) {
            $this->assertEquals('section', $block['type']);
            $this->assertEquals('mrkdwn', $block['text']['type']);
        }

        $this->assertStringContainsString('simple message', $blocks[0]['text']['text']);
        $this->assertStringContainsString('some content', $blocks[1]['text']['text']);
        $this->assertStringContainsString('even more content', $blocks[2]['text']['text']);
    }

    public function test_integration_with_empty_input(): void
    {
        $blocks = $this->formatter->format('');
        $this->assertEmpty($blocks);

        $blocks = $this->formatter->format('   ');
        $this->assertEmpty($blocks);
    }
}
