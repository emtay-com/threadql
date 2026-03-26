<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database;

use App\Infrastructure\Database\DatabaseDriver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DatabaseDriverTest extends TestCase
{
    #[Test]
    public function it_resolves_mysql_from_string(): void
    {
        $driver = DatabaseDriver::from('mysql');
        $this->assertSame(DatabaseDriver::MySQL, $driver);
    }

    #[Test]
    public function it_resolves_pgsql_from_string(): void
    {
        $driver = DatabaseDriver::from('pgsql');
        $this->assertSame(DatabaseDriver::PostgreSQL, $driver);
    }

    #[Test]
    public function it_returns_correct_default_port_for_mysql(): void
    {
        $this->assertEquals(3306, DatabaseDriver::MySQL->defaultPort());
    }

    #[Test]
    public function it_returns_correct_default_port_for_postgres(): void
    {
        $this->assertEquals(5432, DatabaseDriver::PostgreSQL->defaultPort());
    }

    #[Test]
    public function it_returns_mysql_display_name(): void
    {
        $this->assertEquals('MySQL 8', DatabaseDriver::MySQL->displayName());
    }

    #[Test]
    public function it_returns_postgres_display_name(): void
    {
        $this->assertEquals('PostgreSQL', DatabaseDriver::PostgreSQL->displayName());
    }

    #[Test]
    public function it_returns_mysql_sql_dialect(): void
    {
        $this->assertEquals('MySQL 8', DatabaseDriver::MySQL->sqlDialect());
    }

    #[Test]
    public function it_returns_postgres_sql_dialect(): void
    {
        $this->assertEquals('PostgreSQL', DatabaseDriver::PostgreSQL->sqlDialect());
    }

    #[Test]
    public function it_returns_null_for_unknown_driver(): void
    {
        $this->assertNull(DatabaseDriver::tryFrom('oracle'));
    }
}
