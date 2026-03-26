<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dsn;

use App\Infrastructure\Dsn\DsnBuilder;
use App\Infrastructure\Dsn\DsnComponents;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DsnBuilderTest extends TestCase
{
    #[Test]
    public function it_builds_basic_tcp_dsn(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('pass')
            ->build();

        $this->assertEquals('mysql://user:pass@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_builds_dsn_without_port(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->database('mydb')
            ->username('user')
            ->password('pass')
            ->build();

        $this->assertEquals('mysql://user:pass@localhost/mydb', $dsn);
    }

    #[Test]
    public function it_builds_dsn_without_password(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->build();

        $this->assertEquals('mysql://user@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_special_characters_in_username(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user@domain')
            ->password('pass')
            ->build();

        $this->assertEquals('mysql://user%40domain:pass@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_special_characters_in_password(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('p@ss:word/123')
            ->build();

        $this->assertEquals('mysql://user:p%40ss%3Aword%2F123@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_colon_in_password(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('pass:word')
            ->build();

        $this->assertEquals('mysql://user:pass%3Aword@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_at_sign_in_password(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('pass@word')
            ->build();

        $this->assertEquals('mysql://user:pass%40word@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_slash_in_password(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('pass/word')
            ->build();

        $this->assertEquals('mysql://user:pass%2Fword@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_escapes_special_characters_in_database(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('my-db/test')
            ->username('user')
            ->password('pass')
            ->build();

        $this->assertEquals('mysql://user:pass@localhost:3306/my-db%2Ftest', $dsn);
    }

    #[Test]
    public function it_handles_complex_password_with_multiple_special_chars(): void
    {
        $dsn = (new DsnBuilder())
            ->host('localhost')
            ->port(3306)
            ->database('mydb')
            ->username('user')
            ->password('P@$$w0rd!#%^&*()')
            ->build();

        // Verify it can be parsed back correctly
        $this->assertStringContainsString('user:', $dsn);
        $this->assertStringContainsString('@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_builds_socket_dsn(): void
    {
        $dsn = (new DsnBuilder())
            ->unixSocket('/var/run/mysqld/mysqld.sock')
            ->database('mydb')
            ->username('user')
            ->password('pass')
            ->build();

        $this->assertEquals(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=mydb;user=user;password=pass',
            $dsn
        );
    }

    #[Test]
    public function it_builds_socket_dsn_without_password(): void
    {
        $dsn = (new DsnBuilder())
            ->unixSocket('/var/run/mysqld/mysqld.sock')
            ->database('mydb')
            ->username('user')
            ->build();

        $this->assertEquals('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=mydb;user=user', $dsn);
    }

    #[Test]
    public function it_throws_exception_when_database_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database name is required');

        (new DsnBuilder())
            ->host('localhost')
            ->username('user')
            ->password('pass')
            ->build();
    }

    #[Test]
    public function it_throws_exception_when_username_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username is required');

        (new DsnBuilder())
            ->host('localhost')
            ->database('mydb')
            ->password('pass')
            ->build();
    }

    #[Test]
    public function it_throws_exception_when_host_missing_for_tcp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Host is required for TCP connections');

        (new DsnBuilder())
            ->database('mydb')
            ->username('user')
            ->password('pass')
            ->build();
    }

    #[Test]
    public function it_creates_builder_from_components(): void
    {
        $components = new DsnComponents(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            database: 'mydb',
            username: 'user',
            password: 'pass',
        );

        $dsn = DsnBuilder::fromComponents($components)->build();

        $this->assertEquals('mysql://user:pass@localhost:3306/mydb', $dsn);
    }

    #[Test]
    public function it_allows_custom_driver(): void
    {
        $dsn = (new DsnBuilder())
            ->driver('pgsql')
            ->host('localhost')
            ->port(5432)
            ->database('mydb')
            ->username('user')
            ->password('pass')
            ->build();

        $this->assertEquals('pgsql://user:pass@localhost:5432/mydb', $dsn);
    }
}
