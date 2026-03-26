<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dsn;

use App\Infrastructure\Dsn\DsnParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DsnParserTest extends TestCase
{
    private DsnParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new DsnParser();
    }

    #[Test]
    public function it_parses_url_format_dsn(): void
    {
        $components = $this->parser->parse('mysql://user:pass@localhost:3306/mydb');

        $this->assertEquals('mysql', $components->driver);
        $this->assertEquals('localhost', $components->host);
        $this->assertEquals(3306, $components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('user', $components->username);
        $this->assertEquals('pass', $components->password);
        $this->assertNull($components->unixSocket);
        $this->assertFalse($components->isSocketConnection());
    }

    #[Test]
    public function it_parses_url_format_without_port(): void
    {
        $components = $this->parser->parse('mysql://user:pass@localhost/mydb');

        $this->assertEquals('mysql', $components->driver);
        $this->assertEquals('localhost', $components->host);
        $this->assertNull($components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('user', $components->username);
        $this->assertEquals('pass', $components->password);
    }

    #[Test]
    public function it_parses_url_format_without_password(): void
    {
        $components = $this->parser->parse('mysql://user@localhost:3306/mydb');

        $this->assertEquals('mysql', $components->driver);
        $this->assertEquals('localhost', $components->host);
        $this->assertEquals(3306, $components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('user', $components->username);
        $this->assertNull($components->password);
    }

    #[Test]
    public function it_parses_key_value_format_dsn(): void
    {
        $components = $this->parser->parse(
            'mysql:host=127.0.0.1;port=3306;dbname=mydb;user=testuser;password=testpass'
        );

        $this->assertEquals('mysql', $components->driver);
        $this->assertEquals('127.0.0.1', $components->host);
        $this->assertEquals(3306, $components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('testuser', $components->username);
        $this->assertEquals('testpass', $components->password);
    }

    #[Test]
    public function it_parses_socket_dsn(): void
    {
        $components = $this->parser->parse(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=mydb;user=testuser;password=testpass'
        );

        $this->assertEquals('mysql', $components->driver);
        $this->assertEquals('/var/run/mysqld/mysqld.sock', $components->unixSocket);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('testuser', $components->username);
        $this->assertEquals('testpass', $components->password);
        $this->assertTrue($components->isSocketConnection());
    }

    #[Test]
    public function it_decodes_url_encoded_username(): void
    {
        $components = $this->parser->parse('mysql://user%40domain:pass@localhost:3306/mydb');

        $this->assertEquals('user@domain', $components->username);
    }

    #[Test]
    public function it_decodes_url_encoded_password(): void
    {
        $components = $this->parser->parse('mysql://user:p%40ss%3Aword%2F123@localhost:3306/mydb');

        $this->assertEquals('p@ss:word/123', $components->password);
    }

    #[Test]
    public function it_decodes_url_encoded_database(): void
    {
        $components = $this->parser->parse('mysql://user:pass@localhost:3306/my-db%2Ftest');

        $this->assertEquals('my-db/test', $components->database);
    }

    #[Test]
    public function it_handles_complex_encoded_password(): void
    {
        // Password: P@$$w0rd!#%^&*()
        $encoded = 'mysql://user:P%40%24%24w0rd%21%23%25%5E%26%2A%28%29@localhost:3306/mydb';
        $components = $this->parser->parse($encoded);

        $this->assertEquals('P@$$w0rd!#%^&*()', $components->password);
    }

    #[Test]
    public function it_returns_effective_port_default(): void
    {
        $components = $this->parser->parse('mysql://user:pass@localhost/mydb');

        $this->assertNull($components->port);
        $this->assertEquals(3306, $components->getEffectivePort());
    }

    #[Test]
    public function it_returns_effective_host_default(): void
    {
        $components = $this->parser->parse('mysql:dbname=mydb;user=testuser');

        $this->assertNull($components->host);
        $this->assertEquals('127.0.0.1', $components->getEffectiveHost());
    }

    #[Test]
    public function it_roundtrips_with_dsn_builder(): void
    {
        $originalDsn = 'mysql://user:pass@localhost:3306/mydb';

        $components = $this->parser->parse($originalDsn);
        $rebuiltDsn = \App\Infrastructure\Dsn\DsnBuilder::fromComponents($components)->build();

        $this->assertEquals($originalDsn, $rebuiltDsn);
    }

    #[Test]
    public function it_roundtrips_with_special_characters(): void
    {
        $builder = new \App\Infrastructure\Dsn\DsnBuilder();
        $originalDsn = $builder
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user@domain')
            ->password('p@ss:word/123')
            ->build();

        $components = $this->parser->parse($originalDsn);

        $this->assertEquals('user@domain', $components->username);
        $this->assertEquals('p@ss:word/123', $components->password);
        $this->assertEquals('mydb', $components->database);
    }

    // --- PostgreSQL DSN Tests ---

    #[Test]
    public function it_parses_pgsql_url_format_dsn(): void
    {
        $components = $this->parser->parse('pgsql://user:pass@localhost:5432/mydb');

        $this->assertEquals('pgsql', $components->driver);
        $this->assertEquals('localhost', $components->host);
        $this->assertEquals(5432, $components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('user', $components->username);
        $this->assertEquals('pass', $components->password);
        $this->assertNull($components->unixSocket);
        $this->assertFalse($components->isSocketConnection());
    }

    #[Test]
    public function it_parses_pgsql_url_format_without_port(): void
    {
        $components = $this->parser->parse('pgsql://user:pass@localhost/mydb');

        $this->assertEquals('pgsql', $components->driver);
        $this->assertEquals('localhost', $components->host);
        $this->assertNull($components->port);
        $this->assertEquals(5432, $components->getEffectivePort());
        $this->assertEquals('mydb', $components->database);
    }

    #[Test]
    public function it_parses_pgsql_url_format_without_password(): void
    {
        $components = $this->parser->parse('pgsql://user@localhost:5432/mydb');

        $this->assertEquals('pgsql', $components->driver);
        $this->assertEquals('user', $components->username);
        $this->assertNull($components->password);
    }

    #[Test]
    public function it_parses_pgsql_key_value_format_dsn(): void
    {
        $components = $this->parser->parse(
            'pgsql:host=127.0.0.1;port=5432;dbname=mydb;user=testuser;password=testpass'
        );

        $this->assertEquals('pgsql', $components->driver);
        $this->assertEquals('127.0.0.1', $components->host);
        $this->assertEquals(5432, $components->port);
        $this->assertEquals('mydb', $components->database);
        $this->assertEquals('testuser', $components->username);
        $this->assertEquals('testpass', $components->password);
    }

    #[Test]
    public function it_returns_effective_port_5432_for_pgsql(): void
    {
        $components = $this->parser->parse('pgsql://user:pass@localhost/mydb');

        $this->assertNull($components->port);
        $this->assertEquals(5432, $components->getEffectivePort());
    }

    #[Test]
    public function it_roundtrips_pgsql_dsn_with_builder(): void
    {
        $originalDsn = 'pgsql://user:pass@localhost:5432/mydb';

        $components = $this->parser->parse($originalDsn);
        $rebuiltDsn = \App\Infrastructure\Dsn\DsnBuilder::fromComponents($components)->build();

        $this->assertEquals($originalDsn, $rebuiltDsn);
    }

    #[Test]
    public function it_decodes_url_encoded_pgsql_password(): void
    {
        $components = $this->parser->parse('pgsql://user:p%40ss%3Aword@localhost:5432/mydb');

        $this->assertEquals('pgsql', $components->driver);
        $this->assertEquals('p@ss:word', $components->password);
    }

    #[Test]
    public function it_returns_database_driver_enum_for_mysql(): void
    {
        $components = $this->parser->parse('mysql://user:pass@localhost:3306/mydb');

        $driver = $components->getDatabaseDriver();

        $this->assertSame(\App\Infrastructure\Database\DatabaseDriver::MySQL, $driver);
    }

    #[Test]
    public function it_returns_database_driver_enum_for_pgsql(): void
    {
        $components = $this->parser->parse('pgsql://user:pass@localhost:5432/mydb');

        $driver = $components->getDatabaseDriver();

        $this->assertSame(\App\Infrastructure\Database\DatabaseDriver::PostgreSQL, $driver);
    }
}
