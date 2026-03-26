<?php

declare(strict_types=1);

namespace Tests\Unit\CommandHandlers;

use App\Command\ExtractTableDdlCommand;
use App\CommandHandlers\ExtractTableDdlHandler;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractTableDdlHandlerTest extends TestCase
{
    private ExtractTableDdlHandler $handler;

    private DynamicDatabaseConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->handler = new ExtractTableDdlHandler($this->connector);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_throws_exception_when_datasource_not_found(): void
    {
        $command = new ExtractTableDdlCommand(
            tenantId: 1,
            datasourceId: 999,
            schemaName: 'testdb',
            tableName: 'users',
        );

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->handler->__invoke($command);
    }
}
