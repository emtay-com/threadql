<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\ExportCsvAndDeliverJob;
use App\Jobs\SendCsvExportLinkJob;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Models\ToolCall;
use App\Services\Export\CsvDataExporter;
use App\Services\Export\CsvFileGenerator;
use App\Services\Export\SlackCsvUploader;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Unit tests for ExportCsvAndDeliverJob
 */
class ExportCsvAndDeliverJobTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createTestData(): array
    {
        $tenant = Tenant::factory()->create([
            'uuid' => 'test-tenant-uuid',
        ]);
        Datasource::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        return [$query, $tenant, $thread];
    }

    /**
     * Test large export writes to disk and dispatches link notification
     */
    public function test_large_export_writes_to_disk(): void
    {
        Storage::fake('exports');
        Bus::fake([SendCsvExportLinkJob::class]);

        [$query, $tenant] = $this->createTestData();

        $toolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'export_csv',
            'request_payload' => '{}',
            'is_completed' => false,
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, "id,name\n1,Alice\n2,Bob\n");

        // generateFileFromBatches now takes a consumer callable; mock it to return the temp file directly
        $mockFileGen = Mockery::mock(CsvFileGenerator::class);
        $mockFileGen->shouldReceive('generateFileFromBatches')
            ->once()
            ->andReturn($tempFile);
        $mockFileGen->shouldReceive('deleteFile')
            ->once();

        // exportDataBatched is called inside the consumer passed to generateFileFromBatches,
        // but since the mock above short-circuits the consumer, exportDataBatched is not called here
        $mockExporter = Mockery::mock(CsvDataExporter::class);

        $mockUploader = Mockery::mock(SlackCsvUploader::class);

        $job = new ExportCsvAndDeliverJob($query->id, 1, 'SELECT * FROM users', [], null, true, $toolCall->id);

        $job->handle($mockExporter, $mockFileGen, $mockUploader);

        // Verify file was stored on disk
        $files = Storage::disk('exports')->allFiles();
        $this->assertNotEmpty($files);
        $this->assertStringStartsWith('test-tenant-uuid/', $files[0]);

        // Verify tool call was marked completed
        $toolCall->refresh();
        $this->assertTrue($toolCall->is_completed);

        Bus::assertDispatched(SendCsvExportLinkJob::class);
    }

    /**
     * Test large export generates temporary URL
     */
    public function test_large_export_generates_temporary_url(): void
    {
        Storage::fake('exports');
        Bus::fake([SendCsvExportLinkJob::class]);

        [$query, $tenant] = $this->createTestData();

        $toolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'export_csv',
            'request_payload' => '{}',
            'is_completed' => false,
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, "id,name\n1,Alice\n");

        $mockFileGen = Mockery::mock(CsvFileGenerator::class);
        $mockFileGen->shouldReceive('generateFileFromBatches')
            ->once()
            ->andReturn($tempFile);
        $mockFileGen->shouldReceive('deleteFile')
            ->once();

        $mockExporter = Mockery::mock(CsvDataExporter::class);
        $mockUploader = Mockery::mock(SlackCsvUploader::class);

        $job = new ExportCsvAndDeliverJob($query->id, 1, 'SELECT * FROM users', [], null, true, $toolCall->id);

        $job->handle($mockExporter, $mockFileGen, $mockUploader);

        // Verify the tool call response contains a download URL
        $toolCall->refresh();
        $payload = $toolCall->response_payload;
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $this->assertEquals('completed', $payload['status']);
        $this->assertArrayHasKey('download_url', $payload);
    }

    /**
     * Test large export dispatches link notification job
     */
    public function test_large_export_dispatches_link_notification(): void
    {
        Storage::fake('exports');
        Bus::fake([SendCsvExportLinkJob::class]);

        [$query, $tenant] = $this->createTestData();

        $toolCall = ToolCall::create([
            'tenant_id' => $tenant->id,
            'query_id' => $query->id,
            'tool' => 'export_csv',
            'request_payload' => '{}',
            'is_completed' => false,
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, "id,name\n1,Alice\n2,Bob\n3,Charlie\n");

        $mockFileGen = Mockery::mock(CsvFileGenerator::class);
        $mockFileGen->shouldReceive('generateFileFromBatches')
            ->once()
            ->andReturn($tempFile);
        $mockFileGen->shouldReceive('deleteFile')
            ->once();

        $mockExporter = Mockery::mock(CsvDataExporter::class);
        $mockUploader = Mockery::mock(SlackCsvUploader::class);

        $job = new ExportCsvAndDeliverJob($query->id, 1, 'SELECT * FROM users', [], null, true, $toolCall->id);

        $job->handle($mockExporter, $mockFileGen, $mockUploader);

        Bus::assertDispatched(SendCsvExportLinkJob::class, function ($job) use ($query) {
            $reflection = new \ReflectionClass($job);
            $queryIdProp = $reflection->getProperty('queryId');
            $queryIdProp->setAccessible(true);

            return $queryIdProp->getValue($job) === $query->id;
        });
    }

    /**
     * Test small export uploads to Slack
     */
    public function test_small_export_uploads_to_slack(): void
    {
        [$query, $tenant] = $this->createTestData();

        $mockExporter = Mockery::mock(CsvDataExporter::class);
        $mockExporter->shouldReceive('exportData')
            ->once()
            ->andReturn([
                'columns' => ['id', 'name'],
                'rows' => [[
                    'id' => 1,
                    'name' => 'Alice',
                ]],
            ]);

        $mockFileGen = Mockery::mock(CsvFileGenerator::class);
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_test_');
        file_put_contents($tempFile, "id,name\n1,Alice\n");

        $mockFileGen->shouldReceive('generateFile')
            ->once()
            ->andReturn($tempFile);
        $mockFileGen->shouldReceive('deleteFile')
            ->once();

        $mockUploader = Mockery::mock(SlackCsvUploader::class);
        $mockUploader->shouldReceive('uploadFile')
            ->once();

        $job = new ExportCsvAndDeliverJob($query->id, 1, 'SELECT * FROM users', [], 100, false, null);

        $job->handle($mockExporter, $mockFileGen, $mockUploader);

        // Verify mock expectations were met (Mockery handles this in tearDown)
        $this->assertTrue(true);
    }
}
