<?php

declare(strict_types=1);

namespace App\Slack\Formatting\Scanners;

use App\Slack\Formatting\Contracts\TagScannerInterface;

/**
 * Scanner for [TABLE]...[/TABLE] tags that converts CSV content to Slack table blocks
 */
class TableScanner implements TagScannerInterface
{
    private const TABLE_TAG_REGEX = '/\[TABLE\](.*?)\[\/TABLE\]/is';

    private const MAX_TABLE_ROWS = 25;

    public function __construct(
        private int $maxRows = self::MAX_TABLE_ROWS,
        private int $cellMaxLength = 2000
    ) {
    }

    public function matches(string $text): bool
    {
        return preg_match(self::TABLE_TAG_REGEX, $text) === 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transform(string $text): array
    {
        $blocks = [];
        $lastPosition = 0;

        if (! preg_match_all(self::TABLE_TAG_REGEX, $text, $matches, PREG_OFFSET_CAPTURE)) {
            // No table tags found, return empty array to let ResponseFormatter handle as plain text
            return [];
        }

        foreach ($matches[0] as $index => $match) {
            $fullMatch = $match[0];
            $tableContent = $matches[1][$index][0];
            $startPosition = $match[1];
            $endPosition = $startPosition + strlen($fullMatch);

            // Add any text before this table as a section block
            $beforeText = substr($text, $lastPosition, $startPosition - $lastPosition);
            if (! empty(trim($beforeText))) {
                $blocks[] = $this->createSectionBlock($beforeText);
            }

            // Process the table content
            $tableBlocks = $this->processTableContent($tableContent);
            $blocks = array_merge($blocks, $tableBlocks);

            $lastPosition = $endPosition;
        }

        // Add any remaining text after the last table
        $afterText = substr($text, $lastPosition);
        if (! empty(trim($afterText))) {
            $blocks[] = $this->createSectionBlock($afterText);
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function processTableContent(string $tableContent): array
    {
        $csvData = $this->parseCsvContent($tableContent);

        if (empty($csvData)) {
            return [];
        }

        // First row is headers
        $headers = array_shift($csvData);
        $rows = $csvData;

        return $this->createTableBlock($headers, $rows);
    }

    /**
     * @return array<int, array<string>>
     */
    private function parseCsvContent(string $content): array
    {
        // Normalize line endings and trim
        $content = trim(preg_replace('/\r\n|\r/', "\n", $content));

        if (empty($content)) {
            return [];
        }

        $lines = array_filter(explode("\n", $content), fn ($line) => ! empty(trim($line)));
        $data = [];

        foreach ($lines as $line) {
            $row = str_getcsv($line, ',', '"', '\\');
            // Trim whitespace from each cell
            $row = array_map('trim', $row);

            $data[] = $row;
        }

        return $data;
    }

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function createTableBlock(array $headers, array $rows): array
    {
        $blocks = [];

        // Limit rows if necessary
        $originalRowCount = count($rows);
        $limitedRows = array_slice($rows, 0, $this->maxRows);
        $truncated = $originalRowCount > $this->maxRows;

        // Create column settings (all columns left-aligned, not wrapped by default)
        $columnSettings = array_fill(0, count($headers), [
            'align' => 'left',
        ]);

        // Build all rows (headers first, then data rows)
        $allRows = [];

        // Add header row
        $allRows[] = array_map(fn ($header) => $this->createTableCell($header), $headers);

        // Add data rows, normalizing column count to match headers
        foreach ($limitedRows as $row) {
            $normalizedRow = $this->normalizeRowColumns($row, count($headers));
            $allRows[] = array_map(fn ($cell) => $this->createTableCell($cell), $normalizedRow);
        }

        $tableBlock = [
            'type' => 'table',
            'column_settings' => $columnSettings,

            'rows' => $allRows,
        ];

        $blocks[] = $tableBlock;

        // Add truncation notice if needed
        if ($truncated) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "_Showing first {$this->maxRows} of {$originalRowCount} rows._",
                    ],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<string, mixed>
     */
    private function createTableCell(string $text): array
    {
        // Truncate cell text if too long
        if (strlen($text) > $this->cellMaxLength) {
            $text = substr($text, 0, $this->cellMaxLength - 3).'...';
        }

        // add a space to empty cells to avoid slack linter to reject the block
        if ($text == 'null' || $text === '') {
            $text = ' ';
        }

        return [
            'type' => 'raw_text',
            'text' => $text,
        ];
    }

    /**
     * Normalize a row to have exactly the expected number of columns
     *
     * @param array<string> $row
     * @return array<string>
     */
    private function normalizeRowColumns(array $row, int $expectedColumns): array
    {
        $currentColumns = count($row);

        if ($currentColumns === $expectedColumns) {
            return $row;
        }

        if ($currentColumns < $expectedColumns) {
            // Pad with empty strings to match header count
            return array_pad($row, $expectedColumns, '');
        }

        // Truncate if row has more columns than headers
        return array_slice($row, 0, $expectedColumns);
    }

    /**
     * @return array<string, mixed>
     */
    private function createSectionBlock(string $text): array
    {
        return [
            'type' => 'section',
            'text' => [
                'type' => 'mrkdwn',
                'text' => trim($text),
            ],
        ];
    }
}
