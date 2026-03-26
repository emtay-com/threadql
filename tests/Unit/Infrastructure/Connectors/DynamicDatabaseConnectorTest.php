<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Connectors;

use App\Exceptions\TableNotFoundException;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Database\DatabaseDriver;
use App\Infrastructure\Database\Strategies\MysqlQueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\PostgresQueryTimeoutStrategy;
use App\Infrastructure\Ssh\SshTunnelClient;
use App\Infrastructure\Ssh\TunnelConnection;
use App\Models\Datasource;
use Illuminate\Database\Connection;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DynamicDatabaseConnectorTest extends TestCase
{
    private DynamicDatabaseConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = new DynamicDatabaseConnector();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a Datasource mock that returns the given DSN without encryption.
     */
    private function makeDatasource(string $dsn, array $extra = []): Datasource
    {
        $mock = Mockery::mock(Datasource::class);
        $attributes = array_merge([
            'dsn' => $dsn,
            'use_ssh' => false,
        ], $extra);

        $mock->shouldReceive('__get')
            ->andReturnUsing(fn (string $key) => $attributes[$key] ?? null);
        $mock->shouldReceive('getAttribute')
            ->andReturnUsing(fn (string $key) => $attributes[$key] ?? null);

        return $mock;
    }

    #[Test]
    public function it_extracts_database_name_from_host_based_dsn(): void
    {
        $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=mydb';

        $result = $this->connector->databaseNameFromDsn($dsn);

        $this->assertEquals('mydb', $result);
    }

    #[Test]
    public function it_extracts_database_name_from_socket_based_dsn(): void
    {
        $dsn = 'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=testdb';

        $result = $this->connector->databaseNameFromDsn($dsn);

        $this->assertEquals('testdb', $result);
    }

    #[Test]
    public function it_returns_empty_string_for_invalid_dsn(): void
    {
        $dsn = 'invalid:dsn:format';

        $result = $this->connector->databaseNameFromDsn($dsn);

        $this->assertEquals('', $result);
    }

    #[Test]
    public function it_generates_valid_mysql_connection_config_from_datasource(): void
    {
        $datasource = $this->makeDatasource('mysql://readonly_user:secret_password@127.0.0.1:3306/mydb');

        $config = $this->connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('mysql', $config['driver']);
        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(3306, $config['port']);
        $this->assertEquals('mydb', $config['database']);
        $this->assertEquals('readonly_user', $config['username']);
        $this->assertEquals('secret_password', $config['password']);
        $this->assertEquals('utf8mb4', $config['charset']);
        $this->assertEquals('utf8mb4_unicode_ci', $config['collation']);
        $this->assertTrue($config['strict']);
        $this->assertArrayHasKey('options', $config);
        $this->assertArrayHasKey(PDO::ATTR_TIMEOUT, $config['options']);
        $this->assertArrayHasKey(PDO::MYSQL_ATTR_INIT_COMMAND, $config['options']);
        $this->assertEquals('SET SESSION TRANSACTION READ ONLY', $config['options'][PDO::MYSQL_ATTR_INIT_COMMAND]);
    }

    #[Test]
    public function it_generates_valid_postgres_connection_config_from_datasource(): void
    {
        $datasource = $this->makeDatasource('pgsql://readonly_user:secret_password@127.0.0.1:5432/mydb');

        $config = $this->connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('pgsql', $config['driver']);
        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(5432, $config['port']);
        $this->assertEquals('mydb', $config['database']);
        $this->assertEquals('readonly_user', $config['username']);
        $this->assertEquals('secret_password', $config['password']);
        $this->assertEquals('utf8', $config['charset']);
        $this->assertEquals('public', $config['schema']);
        $this->assertEquals('prefer', $config['sslmode']);
        $this->assertArrayNotHasKey('collation', $config);
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_INIT_COMMAND, $config['options']);
    }

    #[Test]
    public function it_generates_socket_based_connection_config(): void
    {
        $datasource = $this->makeDatasource(
            'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=mydb;user=readonly_user;password=secret_password'
        );

        $config = $this->connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('mysql', $config['driver']);
        $this->assertEquals('/var/run/mysqld/mysqld.sock', $config['unix_socket']);
        $this->assertEquals('mydb', $config['database']);
        $this->assertEquals('readonly_user', $config['username']);
        $this->assertArrayNotHasKey('host', $config);
        $this->assertArrayNotHasKey('port', $config);
    }

    #[Test]
    public function it_lists_tables_using_mysql_strategy(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('testdb');
        $connection->shouldReceive('select')
            ->andReturn([
                [
                    'TABLE_NAME' => 'users',
                ],
                [
                    'TABLE_NAME' => 'posts',
                ],
                [
                    'TABLE_NAME' => 'comments',
                ],
            ]);

        $tables = $this->connector->listTables($connection);

        $this->assertEquals(['users', 'posts', 'comments'], $tables);
    }

    #[Test]
    public function it_lists_tables_using_postgres_strategy(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('pgsql');
        $connection->shouldReceive('select')
            ->andReturn([
                [
                    'table_name' => 'users',
                ],
                [
                    'table_name' => 'posts',
                ],
            ]);

        $tables = $this->connector->listTables($connection);

        $this->assertEquals(['users', 'posts'], $tables);
    }

    #[Test]
    public function it_gets_create_table_ddl_using_mysql_strategy(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('mysql');
        $connection->shouldReceive('getQueryGrammar->wrapTable')
            ->with('users')
            ->andReturn('`users`');
        $connection->shouldReceive('select')
            ->with('SHOW CREATE TABLE `users`')
            ->andReturn([
                [
                    'Create Table' => 'CREATE TABLE `users` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
                ],
            ]);

        $ddl = $this->connector->getCreateTableDdl($connection, 'users');

        $this->assertEquals(
            'CREATE TABLE `users` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
            $ddl
        );
    }

    #[Test]
    public function it_throws_exception_when_table_not_found(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('mysql');
        $connection->shouldReceive('getQueryGrammar->wrapTable')
            ->with('nonexistent')
            ->andReturn('`nonexistent`');
        $connection->shouldReceive('select')
            ->with('SHOW CREATE TABLE `nonexistent`')
            ->andReturn([]);

        $this->expectException(TableNotFoundException::class);
        $this->expectExceptionMessage("Table 'nonexistent' not found or not accessible");

        $this->connector->getCreateTableDdl($connection, 'nonexistent');
    }

    #[Test]
    public function it_gets_table_metadata_using_mysql_strategy(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')
            ->andReturn('mysql');
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('testdb');
        $connection->shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'TABLE_ROWS' => 50000,
                    'size_mb' => 12.5,
                ],
            ]);

        $metadata = $this->connector->getTableMetadata($connection, 'users');

        $this->assertEquals(50000, $metadata['row_count']);
        $this->assertEquals(12.5, $metadata['size_mb']);
    }

    #[Test]
    public function it_returns_mysql_timeout_strategy_for_mysql_datasource(): void
    {
        $datasource = $this->makeDatasource('mysql://user:pass@localhost:3306/mydb');

        $strategy = $this->connector->getTimeoutStrategy($datasource);

        $this->assertInstanceOf(MysqlQueryTimeoutStrategy::class, $strategy);
    }

    #[Test]
    public function it_returns_postgres_timeout_strategy_for_postgres_datasource(): void
    {
        $datasource = $this->makeDatasource('pgsql://user:pass@localhost:5432/mydb');

        $strategy = $this->connector->getTimeoutStrategy($datasource);

        $this->assertInstanceOf(PostgresQueryTimeoutStrategy::class, $strategy);
    }

    #[Test]
    public function it_returns_mysql_driver_for_mysql_datasource(): void
    {
        $datasource = $this->makeDatasource('mysql://user:pass@localhost:3306/mydb');

        $driver = $this->connector->getDriver($datasource);

        $this->assertSame(DatabaseDriver::MySQL, $driver);
    }

    #[Test]
    public function it_returns_postgres_driver_for_postgres_datasource(): void
    {
        $datasource = $this->makeDatasource('pgsql://user:pass@localhost:5432/mydb');

        $driver = $this->connector->getDriver($datasource);

        $this->assertSame(DatabaseDriver::PostgreSQL, $driver);
    }

    #[Test]
    public function it_uses_default_port_5432_for_postgres_dsn_without_port(): void
    {
        $datasource = $this->makeDatasource('pgsql://user:pass@localhost/mydb');

        $config = $this->connector->makeConfigFromDatasource($datasource);

        $this->assertEquals(5432, $config['port']);
    }

    #[Test]
    public function it_uses_default_port_3306_for_mysql_dsn_without_port(): void
    {
        $datasource = $this->makeDatasource('mysql://user:pass@localhost/mydb');

        $config = $this->connector->makeConfigFromDatasource($datasource);

        $this->assertEquals(3306, $config['port']);
    }

    #[Test]
    public function it_extracts_database_name_from_postgres_dsn(): void
    {
        $dsn = 'pgsql://user:pass@localhost:5432/mydb';

        $result = $this->connector->databaseNameFromDsn($dsn);

        $this->assertEquals('mydb', $result);
    }

    #[Test]
    public function it_redirects_host_and_port_through_ssh_tunnel_when_use_ssh_is_true(): void
    {
        $tunnelClient = Mockery::mock(SshTunnelClient::class);
        $tunnelClient->shouldReceive('getOrCreateTunnel')
            ->once()
            ->withArgs(function ($datasource, string $remoteHost, int $remotePort) {
                return $remoteHost === '127.0.0.1' && $remotePort === 3306;
            })
            ->andReturn(new TunnelConnection(host: 'threadql-ssh-tunnel', port: 13300));

        $connector = new DynamicDatabaseConnector(sshTunnelClient: $tunnelClient);

        $datasource = $this->makeDatasource(
            'mysql://readonly_user:secret@127.0.0.1:3306/mydb',
            [
                'use_ssh' => true,
            ],
        );

        $config = $connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('threadql-ssh-tunnel', $config['host']);
        $this->assertEquals(13300, $config['port']);
    }

    #[Test]
    public function it_does_not_call_ssh_client_when_use_ssh_is_false(): void
    {
        $tunnelClient = Mockery::mock(SshTunnelClient::class);
        $tunnelClient->shouldNotReceive('getOrCreateTunnel');

        $connector = new DynamicDatabaseConnector(sshTunnelClient: $tunnelClient);

        $datasource = $this->makeDatasource(
            'mysql://readonly_user:secret@127.0.0.1:3306/mydb',
            [
                'use_ssh' => false,
            ],
        );

        $config = $connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(3306, $config['port']);
    }

    #[Test]
    public function it_does_not_call_ssh_client_when_no_client_injected(): void
    {
        // DynamicDatabaseConnector with no SshTunnelClient — SSH fields should be ignored
        $connector = new DynamicDatabaseConnector();

        $datasource = $this->makeDatasource(
            'mysql://readonly_user:secret@127.0.0.1:3306/mydb',
            [
                'use_ssh' => true,
            ],
        );

        // Should not throw — use_ssh is true but there is no client to call
        $config = $connector->makeConfigFromDatasource($datasource);

        $this->assertEquals('127.0.0.1', $config['host']);
        $this->assertEquals(3306, $config['port']);
    }
}
