<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Export;

use App\Services\Export\CsvFileGenerator;
use Tests\TestCase;

/**
 * Unit tests for CsvFileGenerator batch file generation
 */
class CsvFileGeneratorTest extends TestCase
{
    private CsvFileGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new CsvFileGenerator;
    }

    /**
     * Test generate file from batches writes headers once
     */
    public function test_generate_file_from_batches_writes_headers_once(): void
    {
        $filePath = $this->generator->generateFileFromBatches($this->createMultiBatchConsumer(), 1, 1);

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $lines = explode("\n", trim($content));

        // First line should be headers
        $this->assertEquals('id,name', $lines[0]);

        // Headers should appear only once (count occurrences)
        $headerCount = 0;
        foreach ($lines as $line) {
            if ($line === 'id,name') {
                $headerCount++;
            }
        }
        $this->assertEquals(1, $headerCount);

        // Cleanup
        $this->generator->deleteFile($filePath);
    }

    /**
     * Test generate file from batches writes all rows
     */
    public function test_generate_file_from_batches_writes_all_rows(): void
    {
        $filePath = $this->generator->generateFileFromBatches($this->createMultiBatchConsumer(), 1, 1);

        $this->assertFileExists($filePath);

        $content = file_get_contents($filePath);
        $lines = explode("\n", trim($content));

        // 1 header + 4 data rows = 5 lines
        $this->assertCount(5, $lines);
        $this->assertEquals('id,name', $lines[0]);
        $this->assertEquals('1,Alice', $lines[1]);
        $this->assertEquals('2,Bob', $lines[2]);
        $this->assertEquals('3,Charlie', $lines[3]);
        $this->assertEquals('4,Diana', $lines[4]);

        // Cleanup
        $this->generator->deleteFile($filePath);
    }

    /**
     * Returns a consumer callable that feeds two batches of rows to the batch writer
     */
    private function createMultiBatchConsumer(): callable
    {
        return function (callable $writeBatch): void {
            $writeBatch([
                'columns' => ['id', 'name'],
                'rows' => [
                    [
                        'id' => 1,
                        'name' => 'Alice',
                    ],
                    [
                        'id' => 2,
                        'name' => 'Bob',
                    ],
                ],
            ]);

            $writeBatch([
                'columns' => ['id', 'name'],
                'rows' => [
                    [
                        'id' => 3,
                        'name' => 'Charlie',
                    ],
                    [
                        'id' => 4,
                        'name' => 'Diana',
                    ],
                ],
            ]);
        };
    }
}
