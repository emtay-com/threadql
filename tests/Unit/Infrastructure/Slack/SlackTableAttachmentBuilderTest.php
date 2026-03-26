<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Slack;

use App\Infrastructure\Slack\SlackTableAttachmentBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SlackTableAttachmentBuilderTest extends TestCase
{
    private SlackTableAttachmentBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SlackTableAttachmentBuilder();
    }

    #[Test]
    public function it_builds_table_with_columns_and_rows(): void
    {
        $columns = ['id', 'name', 'email'];
        $rows = [
            [
                'id' => 1,
                'name' => 'John',
                'email' => 'john@example.com',
            ],
            [
                'id' => 2,
                'name' => 'Jane',
                'email' => 'jane@example.com',
            ],
        ];

        $result = $this->builder->build($columns, $rows);

        $this->assertArrayHasKey('blocks', $result);
        $this->assertCount(1, $result['blocks']);

        $tableBlock = $result['blocks'][0];
        $this->assertEquals('table', $tableBlock['type']);
        $this->assertCount(3, $tableBlock['column_settings']);
        $this->assertCount(3, $tableBlock['rows']); // 1 header + 2 data rows
    }

    #[Test]
    public function it_builds_header_row_first(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            [
                'id' => 1,
                'name' => 'John',
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];

        // First row should be headers
        $headerRow = $tableBlock['rows'][0];
        $this->assertEquals('id', $headerRow[0]['text']);
        $this->assertEquals('name', $headerRow[1]['text']);
        $this->assertEquals('raw_text', $headerRow[0]['type']);
    }

    #[Test]
    public function it_builds_data_rows_after_header(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            [
                'id' => 1,
                'name' => 'John',
            ],
            [
                'id' => 2,
                'name' => 'Jane',
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];

        // Second row should be first data row
        $dataRow1 = $tableBlock['rows'][1];
        $this->assertEquals('1', $dataRow1[0]['text']);
        $this->assertEquals('John', $dataRow1[1]['text']);

        // Third row should be second data row
        $dataRow2 = $tableBlock['rows'][2];
        $this->assertEquals('2', $dataRow2[0]['text']);
        $this->assertEquals('Jane', $dataRow2[1]['text']);
    }

    #[Test]
    public function it_handles_empty_rows(): void
    {
        $columns = ['id', 'name'];
        $rows = [];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];

        // Should only have header row
        $this->assertCount(1, $tableBlock['rows']);
    }

    #[Test]
    public function it_pads_rows_with_fewer_columns_than_headers(): void
    {
        $columns = ['id', 'name', 'email'];
        $rows = [
            [
                'id' => 1,
                'name' => 'John',
            ], // Missing email
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertCount(3, $dataRow);
        $this->assertEquals(' ', $dataRow[2]['text']); // Padded with space (empty becomes space)
    }

    #[Test]
    public function it_truncates_rows_with_more_columns_than_headers(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            [
                'id' => 1,
                'name' => 'John',
                'email' => 'john@example.com',
                'extra' => 'data',
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertCount(2, $dataRow);
        $this->assertEquals('1', $dataRow[0]['text']);
        $this->assertEquals('John', $dataRow[1]['text']);
    }

    #[Test]
    public function it_converts_empty_cells_to_space(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            [
                'id' => 1,
                'name' => '',
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertEquals(' ', $dataRow[1]['text']);
    }

    #[Test]
    public function it_truncates_long_cell_text(): void
    {
        $columns = ['content'];
        $longText = str_repeat('a', 2500);
        $rows = [
            [
                'content' => $longText,
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertLessThanOrEqual(2000, strlen($dataRow[0]['text']));
        $this->assertStringEndsWith('...', $dataRow[0]['text']);
    }

    #[Test]
    public function it_converts_non_string_values_to_strings(): void
    {
        $columns = ['id', 'count', 'active'];
        $rows = [
            [
                'id' => 123,
                'count' => 45.67,
                'active' => true,
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertEquals('123', $dataRow[0]['text']);
        $this->assertEquals('45.67', $dataRow[1]['text']);
        $this->assertEquals('1', $dataRow[2]['text']); // true becomes "1"
    }

    #[Test]
    public function it_sets_all_columns_to_left_aligned(): void
    {
        $columns = ['id', 'name', 'email'];
        $rows = [];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];

        foreach ($tableBlock['column_settings'] as $setting) {
            $this->assertEquals('left', $setting['align']);
        }
    }

    #[Test]
    public function it_handles_null_values(): void
    {
        $columns = ['id', 'name'];
        $rows = [
            [
                'id' => 1,
                'name' => null,
            ],
        ];

        $result = $this->builder->build($columns, $rows);
        $tableBlock = $result['blocks'][0];
        $dataRow = $tableBlock['rows'][1];

        $this->assertEquals(' ', $dataRow[1]['text']); // null becomes empty string, then space
    }
}
