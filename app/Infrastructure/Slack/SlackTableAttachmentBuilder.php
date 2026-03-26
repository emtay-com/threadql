<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

/**
 * Builds Slack Block Kit table attachments from column/row data.
 */
class SlackTableAttachmentBuilder
{
    private const MAX_CELL_LENGTH = 2000;

    /**
     * Build a table attachment from columns and rows.
     *
     * @param array<string> $columns Column headers
     * @param array<array<mixed>> $rows Data rows
     * @return array<string, mixed> Slack attachment payload with blocks
     */
    public function build(array $columns, array $rows): array
    {
        $columnSettings = $this->buildColumnSettings(count($columns));
        $allRows = $this->buildAllRows($columns, $rows);

        $tableBlock = [
            'type' => 'table',
            'column_settings' => $columnSettings,
            'rows' => $allRows,
        ];

        return [
            'blocks' => [$tableBlock],
        ];
    }

    /**
     * Build column settings (all columns left-aligned).
     *
     * @return array<array{align: string}>
     */
    private function buildColumnSettings(int $columnCount): array
    {
        return array_fill(0, $columnCount, [
            'align' => 'left',
        ]);
    }

    /**
     * Build all rows including header and data rows.
     *
     * @param array<string> $columns
     * @param array<array<mixed>> $rows
     * @return array<array<array{type: string, text: string}>>
     */
    private function buildAllRows(array $columns, array $rows): array
    {
        $allRows = [];

        // Add header row
        $allRows[] = array_map(fn ($header) => $this->createTableCell($header), $columns);

        // Add data rows - extract values in column order
        foreach ($rows as $row) {
            $rowCells = $this->extractRowValues($row, $columns);
            $allRows[] = array_map(fn ($cell) => $this->createTableCell($cell), $rowCells);
        }

        return $allRows;
    }

    /**
     * Extract values from a row in column order.
     *
     * @param array<mixed> $row
     * @param array<string> $columns
     * @return array<mixed>
     */
    private function extractRowValues(array $row, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? '';
        }

        return $values;
    }

    /**
     * Create a table cell for Slack Block Kit.
     *
     * @return array{type: string, text: string}
     */
    private function createTableCell(mixed $text): array
    {
        $text = (string) $text;

        // Truncate cell text if too long
        if (strlen($text) > self::MAX_CELL_LENGTH) {
            $text = substr($text, 0, self::MAX_CELL_LENGTH - 3).'...';
        }

        // Add a space to empty cells to avoid Slack linter issues
        if ($text === '') {
            $text = ' ';
        }

        return [
            'type' => 'raw_text',
            'text' => $text,
        ];
    }
}
