<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Jobs\TableSchemaCrawlerJob;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TableSchemaCrawlerJobTest extends TestCase
{
    private DynamicDatabaseConnector $connector;

    private DomainCommandBus $commandBus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->commandBus = Mockery::mock(DomainCommandBus::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_has_sensible_retry_settings(): void
    {
        $job = new TableSchemaCrawlerJob(1);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals(60, $job->backoff);
    }

    #[Test]
    public function it_is_queueable(): void
    {
        $job = new TableSchemaCrawlerJob(1);

        $this->assertTrue($job instanceof \Illuminate\Contracts\Queue\ShouldQueue);
    }
}
