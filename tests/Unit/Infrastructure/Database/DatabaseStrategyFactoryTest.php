<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\DatabaseDriver;
use App\Infrastructure\Database\DatabaseStrategyFactory;
use App\Infrastructure\Database\Strategies\MysqlQueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\MysqlSchemaStrategy;
use App\Infrastructure\Database\Strategies\PostgresQueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\PostgresSchemaStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatabaseStrategyFactoryTest extends TestCase
{
    private DatabaseStrategyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new DatabaseStrategyFactory();
    }

    #[Test]
    public function it_creates_mysql_timeout_strategy(): void
    {
        $strategy = $this->factory->makeTimeoutStrategy(DatabaseDriver::MySQL);
        $this->assertInstanceOf(MysqlQueryTimeoutStrategy::class, $strategy);
    }

    #[Test]
    public function it_creates_postgres_timeout_strategy(): void
    {
        $strategy = $this->factory->makeTimeoutStrategy(DatabaseDriver::PostgreSQL);
        $this->assertInstanceOf(PostgresQueryTimeoutStrategy::class, $strategy);
    }

    #[Test]
    public function it_creates_mysql_schema_strategy(): void
    {
        $strategy = $this->factory->makeSchemaStrategy(DatabaseDriver::MySQL);
        $this->assertInstanceOf(MysqlSchemaStrategy::class, $strategy);
    }

    #[Test]
    public function it_creates_postgres_schema_strategy(): void
    {
        $strategy = $this->factory->makeSchemaStrategy(DatabaseDriver::PostgreSQL);
        $this->assertInstanceOf(PostgresSchemaStrategy::class, $strategy);
    }

    #[Test]
    public function it_resolves_mysql_driver_from_string(): void
    {
        $driver = $this->factory->resolveDriver('mysql');
        $this->assertSame(DatabaseDriver::MySQL, $driver);
    }

    #[Test]
    public function it_resolves_pgsql_driver_from_string(): void
    {
        $driver = $this->factory->resolveDriver('pgsql');
        $this->assertSame(DatabaseDriver::PostgreSQL, $driver);
    }

    #[Test]
    public function it_throws_exception_for_unsupported_driver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported database driver: oracle');

        $this->factory->resolveDriver('oracle');
    }
}
