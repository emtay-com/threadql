<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Jobs\TableSchemaCrawlerJob;
use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractSchemaCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_job_for_existing_datasource(): void
    {
        // Create a tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create a datasource
        $datasource = Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $this->artisan('schema:extract', [
            'datasource_id' => $datasource->id,
        ])
            ->expectsOutput("Starting schema extraction for datasource ID: {$datasource->id}")
            ->expectsOutput('Schema extraction job dispatched successfully.')
            ->expectsOutput('Check the queue and logs for progress.')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class);
    }

    #[Test]
    public function it_dispatches_job_with_dsn_override(): void
    {
        // Create a tenant
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create a datasource
        $datasource = Datasource::create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/originaldb',
        ]);

        $dsnOverride = 'mysql:host=127.0.0.1;port=3306;dbname=overridedb';

        $this->artisan('schema:extract', [
            'datasource_id' => $datasource->id,
            '--dsn-override' => $dsnOverride,
        ])
            ->expectsOutput("Starting schema extraction for datasource ID: {$datasource->id}")
            ->expectsOutput("Using DSN override: {$dsnOverride}")
            ->expectsOutput('Schema extraction job dispatched successfully.')
            ->expectsOutput('Check the queue and logs for progress.')
            ->assertExitCode(0);

        Queue::assertPushed(TableSchemaCrawlerJob::class);
    }

    #[Test]
    public function it_fails_for_nonexistent_datasource(): void
    {
        $this->artisan('schema:extract', [
            'datasource_id' => 999,
        ])
            ->expectsOutput('Datasource with ID 999 not found.')
            ->assertExitCode(1);

        Queue::assertNotPushed(TableSchemaCrawlerJob::class);
    }
}
