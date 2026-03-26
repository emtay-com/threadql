<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Export;

use App\Domain\Export\ExportCsvService;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Mockery;
use Tests\TestCase;

/**
 * Test the ExportCsvService functionality
 */
class ExportCsvServiceTest extends TestCase
{
    private ExportCsvService $service;

    private DynamicDatabaseConnector $mockConnector;

    private SlackMessenger $mockSlackMessenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConnector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->mockSlackMessenger = Mockery::mock(SlackMessenger::class);

        $this->service = new ExportCsvService($this->mockConnector, $this->mockSlackMessenger);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(ExportCsvService::class, $this->service);
    }

    public function test_get_row_count_handles_missing_datasource(): void
    {
        // Create test data without datasource
        $tenant = Tenant::factory()->create();

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        // Load relationships (tenant has no datasources)
        $query->load(['tenant.datasources']);

        // The service should return 0 when no datasource is available
        // (it should catch the exception and return 0)
        $rowCount = $this->service->getRowCount($query, 'SELECT * FROM users', []);

        $this->assertEquals(0, $rowCount);
    }

    public function test_export_full_query_to_csv_happy_path(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        // Add a datasource to the tenant
        $datasource = \App\Models\Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $query->load(['tenant.datasources', 'thread']);

        $sql = 'SELECT id, name, email FROM users WHERE active = :active';
        $params = [
            'active' => 1,
        ];
        $rowCount = 3;

        // Mock data that would be returned from the database
        $mockRows = [
            [
                'id' => 1,
                'name' => 'Alice',
                'email' => 'alice@example.com',
            ],
            [
                'id' => 2,
                'name' => 'Bob',
                'email' => 'bob@example.com',
            ],
            [
                'id' => 3,
                'name' => 'Charlie',
                'email' => 'charlie@example.com',
            ],
        ];

        // Mock the connector's withConnection method
        $this->mockConnector->shouldReceive('withConnection')
            ->once()
            ->andReturnUsing(function ($datasource, $callback) use ($mockRows) {
                // Create mock PDO and statement
                $mockStatement = Mockery::mock(\PDOStatement::class);
                $mockStatement->shouldReceive('execute')
                    ->once();
                $mockStatement->shouldReceive('fetchAll')
                    ->with(\PDO::FETCH_ASSOC)
                    ->andReturn($mockRows);

                $mockPdo = Mockery::mock(\PDO::class);
                $mockPdo->shouldReceive('prepare')
                    ->once()
                    ->andReturn($mockStatement);

                $mockConnection = Mockery::mock(\Illuminate\Database\Connection::class);
                $mockConnection->shouldReceive('getPdo')
                    ->andReturn($mockPdo);

                // Execute the callback with the mock connection
                return $callback($mockConnection);
            });

        // Mock Slack messenger uploadFile
        $this->mockSlackMessenger->shouldReceive('uploadFile')
            ->once()
            ->andReturn(true);

        // Execute the export
        $result = $this->service->exportFullQueryToCsv($query, $sql, $params, $rowCount);

        // Assert the result
        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->bytes);
        $this->assertEquals($rowCount, $result->rowCount);
        $this->assertNotNull($result->filePath);
        $this->assertFileExists($result->filePath);

        // Clean up the temp file
        if ($result->filePath && file_exists($result->filePath)) {
            unlink($result->filePath);
        }
    }
}
