<?php

declare(strict_types=1);

namespace Tests\Unit\Slack\Formatting;

use App\Slack\Formatting\Scanners\TableScanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class TableScannerTest extends TestCase
{
    private TableScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new TableScanner(
            25, // maxRows
            2000 // cellMaxLength
        );
    }

    #[Test]
    public function it_detects_table_tags(): void
    {
        $textWithTable = "Here's a table:\n[TABLE]\nName,Age\nJohn,30\nJane,25\n[/TABLE]";
        $textWithoutTable = 'This is just plain text without any tables.';

        $this->assertTrue($this->scanner->matches($textWithTable));
        $this->assertFalse($this->scanner->matches($textWithoutTable));
    }

    #[Test]
    public function it_converts_single_table_to_table_block_with_headers_and_rows(): void
    {
        $input = "[TABLE]\nName,Age,City\nJohn,30,New York\nJane,25,London\nBob,35,Paris\n[/TABLE]";

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);

        // Check column settings
        $this->assertArrayHasKey('column_settings', $blocks[0]);
        $this->assertCount(3, $blocks[0]['column_settings']);

        // Check rows (headers + 3 data rows = 4 total)
        $this->assertCount(4, $blocks[0]['rows']);

        // Check header row (first row)
        $headerRow = $blocks[0]['rows'][0];
        $this->assertCount(3, $headerRow);
        $this->assertEquals('Name', $headerRow[0]['text']);
        $this->assertEquals('Age', $headerRow[1]['text']);
        $this->assertEquals('City', $headerRow[2]['text']);

        // Check first data row
        $firstDataRow = $blocks[0]['rows'][1];
        $this->assertCount(3, $firstDataRow);
        $this->assertEquals('John', $firstDataRow[0]['text']);
        $this->assertEquals('30', $firstDataRow[1]['text']);
        $this->assertEquals('New York', $firstDataRow[2]['text']);
    }

    #[Test]
    public function it_handles_text_before_and_after_table_as_sections(): void
    {
        $input = "Here are the results:\n\n[TABLE]\nName,Value\nTest,123\n[/TABLE]\n\nThat's all for now.";

        $blocks = $this->scanner->transform($input);

        $this->assertCount(3, $blocks);

        // First block should be section with text before table
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertStringContainsString('Here are the results:', $blocks[0]['text']['text']);

        // Second block should be the table
        $this->assertEquals('table', $blocks[1]['type']);
        $this->assertCount(2, $blocks[1]['rows']); // Headers + 1 data row

        // Third block should be section with text after table
        $this->assertEquals('section', $blocks[2]['type']);
        $this->assertStringContainsString("That's all for now.", $blocks[2]['text']['text']);
    }

    #[Test]
    public function it_handles_multiple_tables_and_inserts_dividers(): void
    {
        $input = "First table:\n\n[TABLE]\nA,1\nB,2\n[/TABLE]\n\nSecond table:\n\n[TABLE]\nX,10\nY,20\n[/TABLE]";

        $blocks = $this->scanner->transform($input);

        $this->assertCount(4, $blocks);

        // Section before first table
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertStringContainsString('First table:', $blocks[0]['text']['text']);

        // First table
        $this->assertEquals('table', $blocks[1]['type']);
        $this->assertCount(2, $blocks[1]['rows']); // Headers + 1 data row

        // Section between tables
        $this->assertEquals('section', $blocks[2]['type']);
        $this->assertStringContainsString('Second table:', $blocks[2]['text']['text']);

        // Second table
        $this->assertEquals('table', $blocks[3]['type']);
        $this->assertCount(2, $blocks[3]['rows']); // Headers + 1 data row
    }

    #[Test]
    public function it_parses_quoted_commas(): void
    {
        $input = '[TABLE]
Company,Revenue
"ACME, Inc.",1000000
"Global Corp",2000000
[/TABLE]';

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);

        $rows = $blocks[0]['rows'];
        $this->assertCount(3, $rows); // Headers + 2 data rows

        // First data row should have quoted company name as one cell
        $firstDataRow = $rows[1];
        $this->assertEquals('ACME, Inc.', $firstDataRow[0]['text']);
        $this->assertEquals('1000000', $firstDataRow[1]['text']);

        // Second data row should be normal
        $secondDataRow = $rows[2];
        $this->assertEquals('Global Corp', $secondDataRow[0]['text']);
        $this->assertEquals('2000000', $secondDataRow[1]['text']);
    }

    #[Test]
    public function it_limits_rows_and_adds_context_notice(): void
    {
        $scanner = new TableScanner(2, 2000); // Limit to 2 rows

        $input = "[TABLE]\nName,Value\nRow1,1\nRow2,2\nRow3,3\nRow4,4\n[/TABLE]";

        $blocks = $scanner->transform($input);

        $this->assertCount(2, $blocks);

        // First block is the table
        $this->assertEquals('table', $blocks[0]['type']);
        $this->assertCount(3, $blocks[0]['rows']); // Headers + 2 data rows shown

        // Second block is the context notice
        $this->assertEquals('context', $blocks[1]['type']);
        $this->assertStringContainsString('Showing first 2 of 4 rows', $blocks[1]['elements'][0]['text']);
    }

    #[Test]
    public function it_creates_table_block(): void
    {
        $scanner = new TableScanner(25, 2000);

        $input = "[TABLE]\nName,Age\nJohn,30\nJane,25\n[/TABLE]";

        $blocks = $scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);
        $this->assertCount(3, $blocks[0]['rows']); // 1 header + 2 data rows
        $this->assertEquals('Name', $blocks[0]['rows'][0][0]['text']);
        $this->assertEquals('Age', $blocks[0]['rows'][0][1]['text']);
    }

    #[Test]
    public function it_handles_empty_table_region(): void
    {
        $input = 'Before [TABLE][/TABLE] After';

        $blocks = $this->scanner->transform($input);

        $this->assertCount(2, $blocks);
        $this->assertEquals('section', $blocks[0]['type']);
        $this->assertEquals('section', $blocks[1]['type']);
    }

    #[Test]
    public function it_handles_single_column_tables(): void
    {
        $input = "[TABLE]\nItems\nApple\nBanana\nOrange\n[/TABLE]";

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);
        $this->assertCount(1, $blocks[0]['column_settings']); // 1 column
        $this->assertCount(4, $blocks[0]['rows']); // Headers + 3 data rows
    }

    #[Test]
    public function it_handles_headers_only_table(): void
    {
        $input = "[TABLE]\nName,Age,City\n[/TABLE]";

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);
        $this->assertCount(3, $blocks[0]['column_settings']); // 3 columns
        $this->assertCount(1, $blocks[0]['rows']); // Just header row
    }

    #[Test]
    public function it_truncates_long_cell_content(): void
    {
        $scanner = new TableScanner(25, 10); // Short cell limit

        $longText = 'This is a very long text that should be truncated';
        $input = "[TABLE]\nColumn\n{$longText}\n[/TABLE]";

        $blocks = $scanner->transform($input);

        $cellText = $blocks[0]['rows'][1][0]['text']; // First data row, first column
        $this->assertEquals(10, strlen($cellText));
        $this->assertStringEndsWith('...', $cellText);
    }

    #[Test]
    public function it_normalizes_rows_with_different_column_counts(): void
    {
        // Test case from user: headers have 11 columns, data rows have 10
        $input = "[TABLE]\n".
                 "id,sku,name,stock,warehouse_stock,width,height,depth,weight,created_at,updated_at\n".
                 "1,AVALON-XS,Avalon,95,37,7,4,,2025-02-20 06:50:43,2025-02-20 06:50:43\n".
                 "2,AVALON-S,Avalon,110,209893,9,3,,2025-02-20 06:50:43,2025-02-20 06:50:43\n".
                 "3,AVALON-M,Avalon,73,91,1,3,,2025-02-20 06:50:43,2025-02-20 06:50:43\n".
                 '[/TABLE]';

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);

        // Should have 11 columns in column_settings
        $this->assertCount(11, $blocks[0]['column_settings']);

        // Should have 4 rows total: 1 header + 3 data rows
        $this->assertCount(4, $blocks[0]['rows']);

        // All rows should have exactly 11 columns (normalized)
        foreach ($blocks[0]['rows'] as $rowIndex => $row) {
            $this->assertCount(11, $row, "Row {$rowIndex} should have 11 columns");

            // For data rows (index > 0), check that missing columns are padded with spaces
            if ($rowIndex > 0) {
                // The last column (index 10) should be padded with space (no updated_at in original data)
                $this->assertEquals(
                    ' ',
                    $row[10]['text'],
                    "Row {$rowIndex}, column 10 (updated_at) should be padded with space"
                );
            }
        }

        // Verify header row
        $headerRow = $blocks[0]['rows'][0];
        $this->assertEquals('id', $headerRow[0]['text']);
        $this->assertEquals('sku', $headerRow[1]['text']);
        $this->assertEquals('name', $headerRow[2]['text']);
        $this->assertEquals('stock', $headerRow[3]['text']);
        $this->assertEquals('warehouse_stock', $headerRow[4]['text']);
        $this->assertEquals('width', $headerRow[5]['text']);
        $this->assertEquals('height', $headerRow[6]['text']);
        $this->assertEquals('depth', $headerRow[7]['text']);
        $this->assertEquals('weight', $headerRow[8]['text']);
        $this->assertEquals('created_at', $headerRow[9]['text']);
        $this->assertEquals('updated_at', $headerRow[10]['text']);

        // Verify first data row (original has 10 columns, should be padded to 11)
        $firstDataRow = $blocks[0]['rows'][1];
        $this->assertEquals('1', $firstDataRow[0]['text']);
        $this->assertEquals('AVALON-XS', $firstDataRow[1]['text']);
        $this->assertEquals('Avalon', $firstDataRow[2]['text']);
        $this->assertEquals('95', $firstDataRow[3]['text']);
        $this->assertEquals('37', $firstDataRow[4]['text']);
        $this->assertEquals('7', $firstDataRow[5]['text']);
        $this->assertEquals('4', $firstDataRow[6]['text']);
        $this->assertEquals(' ', $firstDataRow[7]['text']); // empty depth field from CSV
        $this->assertEquals('2025-02-20 06:50:43', $firstDataRow[8]['text']);
        $this->assertEquals('2025-02-20 06:50:43', $firstDataRow[9]['text']);
        $this->assertEquals(' ', $firstDataRow[10]['text']); // padded (no updated_at in original data row)
    }

    #[Test]
    public function it_truncates_rows_with_more_columns_than_headers(): void
    {
        $input = "[TABLE]\n".
                 "Name,Age\n".
                 "John,30,Extra,Column\n".
                 "Jane,25\n".
                 '[/TABLE]';

        $blocks = $this->scanner->transform($input);

        $this->assertCount(1, $blocks);
        $this->assertEquals('table', $blocks[0]['type']);

        // Should have 2 columns
        $this->assertCount(2, $blocks[0]['column_settings']);

        // Should have 3 rows total: 1 header + 2 data rows
        $this->assertCount(3, $blocks[0]['rows']);

        // First data row should be truncated to 2 columns
        $firstDataRow = $blocks[0]['rows'][1];
        $this->assertCount(2, $firstDataRow);
        $this->assertEquals('John', $firstDataRow[0]['text']);
        $this->assertEquals('30', $firstDataRow[1]['text']);

        // Second data row should be normal (2 columns)
        $secondDataRow = $blocks[0]['rows'][2];
        $this->assertCount(2, $secondDataRow);
        $this->assertEquals('Jane', $secondDataRow[0]['text']);
        $this->assertEquals('25', $secondDataRow[1]['text']);
    }
}
