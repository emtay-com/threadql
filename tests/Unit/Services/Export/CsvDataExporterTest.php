<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Export;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Datasource;
use App\Services\Export\CsvDataExporter;
use Illuminate\Database\Connection;
use Mockery;
use PDO;
use PDOStatement;
use Tests\TestCase;

/**
 * Unit tests for CsvDataExporter batched export functionality
 */
class CsvDataExporterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test export data batched yields correct batch sizes
     */
    public function test_export_data_batched_yields_correct_batch_sizes(): void
    {
        // Create 25000 mock rows (should yield 3 batches: 10000, 10000, 5000)
        $totalRows = 25000;
        $rowIndex = 0;

        $stmt = Mockery::mock(PDOStatement::class);
        $stmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        $stmt->shouldReceive('bindValue')
            ->andReturn(true);
        $stmt->shouldReceive('fetch')
            ->andReturnUsing(function () use (&$rowIndex, $totalRows) {
                if ($rowIndex >= $totalRows) {
                    return false;
                }
                $rowIndex++;

                return [
                    'id' => $rowIndex,
                    'name' => 'User '.$rowIndex,
                ];
            });

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')
            ->once()
            ->andReturn($stmt);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdo);

        $timeoutStrategy = Mockery::mock(\App\Infrastructure\Database\Strategies\QueryTimeoutStrategy::class);
        $timeoutStrategy->shouldReceive('setTimeout')
            ->once();

        $connector = Mockery::mock(DynamicDatabaseConnector::class);
        $connector->shouldReceive('getTimeoutStrategy')
            ->once()
            ->andReturn($timeoutStrategy);
        $connector->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(function ($datasource, $callback) use ($connection) {
                return $callback($connection);
            });

        $exporter = new CsvDataExporter($connector);
        $datasource = Mockery::mock(Datasource::class);

        $batchSizes = [];
        $columnsSeen = [];

        $exporter->exportDataBatched($datasource, 'SELECT * FROM users', [], 25000, function (array $batch) use (
            &$batchSizes,
            &$columnsSeen
        ) {
            $batchSizes[] = count($batch['rows']);
            $columnsSeen[] = $batch['columns'];
        });

        $this->assertEquals([10000, 10000, 5000], $batchSizes);
        $this->assertEquals($totalRows, array_sum($batchSizes));

        foreach ($columnsSeen as $columns) {
            $this->assertEquals(['id', 'name'], $columns);
        }
    }

    /**
     * Test export data batched includes columns in each batch
     */
    public function test_export_data_batched_includes_columns_in_each_batch(): void
    {
        $totalRows = 15000;
        $rowIndex = 0;

        $stmt = Mockery::mock(PDOStatement::class);
        $stmt->shouldReceive('execute')
            ->once()
            ->andReturn(true);
        $stmt->shouldReceive('bindValue')
            ->andReturn(true);
        $stmt->shouldReceive('fetch')
            ->andReturnUsing(function () use (&$rowIndex, $totalRows) {
                if ($rowIndex >= $totalRows) {
                    return false;
                }
                $rowIndex++;

                return [
                    'id' => $rowIndex,
                    'email' => 'user'.$rowIndex.'@test.com',
                ];
            });

        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')
            ->once()
            ->andReturn($stmt);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getPdo')
            ->once()
            ->andReturn($pdo);

        $timeoutStrategy = Mockery::mock(\App\Infrastructure\Database\Strategies\QueryTimeoutStrategy::class);
        $timeoutStrategy->shouldReceive('setTimeout')
            ->once();

        $connector = Mockery::mock(DynamicDatabaseConnector::class);
        $connector->shouldReceive('getTimeoutStrategy')
            ->once()
            ->andReturn($timeoutStrategy);
        $connector->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(function ($datasource, $callback) use ($connection) {
                return $callback($connection);
            });

        $exporter = new CsvDataExporter($connector);
        $datasource = Mockery::mock(Datasource::class);

        $batchCount = 0;

        $exporter->exportDataBatched($datasource, 'SELECT * FROM users', [], null, function (array $batch) use (
            &$batchCount
        ) {
            $this->assertArrayHasKey('columns', $batch);
            $this->assertArrayHasKey('rows', $batch);
            $this->assertEquals(['id', 'email'], $batch['columns']);
            $batchCount++;
        });

        $this->assertEquals(2, $batchCount); // 10000 + 5000
    }
}
