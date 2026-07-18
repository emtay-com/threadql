<?php

declare(strict_types=1);

namespace App\Infrastructure\Connectors;

use App\Exceptions\DatabaseConnectionException;
use App\Infrastructure\Database\DatabaseDriver;
use App\Infrastructure\Database\DatabaseStrategyFactory;
use App\Infrastructure\Database\Strategies\QueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\SchemaIntrospectionStrategy;
use App\Infrastructure\Dsn\DsnParser;
use App\Infrastructure\Ssh\SshTunnelClient;
use App\Models\Datasource;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * Dynamic database connector for creating temporary connections
 * from Datasource DSNs and performing schema operations.
 */
class DynamicDatabaseConnector
{
    private const CONNECTION_PREFIX = 'dynamic_';

    private const CONNECTION_TIMEOUT = 30; // seconds

    public function __construct(
        private readonly DsnParser $dsnParser = new DsnParser(),
        private readonly DatabaseStrategyFactory $strategyFactory = new DatabaseStrategyFactory(),
        private readonly ?SshTunnelClient $sshTunnelClient = null,
    ) {
    }

    /**
     * Build a Laravel connection config array from a Datasource.
     */
    public function makeConfigFromDatasource(Datasource $datasource): array
    {
        $dsn = $datasource->dsn;
        $components = $this->dsnParser->parse($dsn);
        $driver = $components->getDatabaseDriver();

        $config = [
            'driver' => $driver->value,
            'host' => $components->getEffectiveHost(),
            'port' => $components->getEffectivePort(),
            'database' => $components->database ?? '',
            'username' => $components->username ?? '',
            'password' => $components->password ?? '',
            'prefix' => '',
            'options' => [
                PDO::ATTR_TIMEOUT => self::CONNECTION_TIMEOUT,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];

        // Apply driver-specific configuration
        $this->applyDriverConfig($config, $driver);

        // Handle socket-based connections (MySQL only)
        if ($components->isSocketConnection()) {
            $config['unix_socket'] = $components->unixSocket;
            unset($config['host'], $config['port']);
        }

        // Redirect through SSH tunnel when requested
        if ($datasource->use_ssh && $this->sshTunnelClient !== null) {
            $tunnel = $this->sshTunnelClient->getOrCreateTunnel(
                $datasource,
                remoteHost: $config['host'],
                remotePort: $config['port'],
            );
            $config['host'] = $tunnel->host;
            $config['port'] = $tunnel->port;
        }

        return $config;
    }

    /**
     * Run a callback with a temporary named connection and then purge it.
     */
    public function withConnection(Datasource $datasource, callable $callback)
    {
        $connectionName = $this->createTemporaryConnection($datasource);

        try {
            return $callback(DB::connection($connectionName));
        } finally {
            $this->purgeConnection($connectionName);
        }
    }

    /**
     * List base table names for the current schema (exclude views).
     */
    public function listTables(Connection $connection): array
    {
        $driver = $this->resolveDriverFromConnection($connection);
        $strategy = $this->strategyFactory->makeSchemaStrategy($driver);

        return $strategy->listTables($connection);
    }

    /**
     * Get the DDL (CREATE TABLE statement) for a given table.
     */
    public function getCreateTableDdl(Connection $connection, string $tableName): string
    {
        $driver = $this->resolveDriverFromConnection($connection);
        $strategy = $this->strategyFactory->makeSchemaStrategy($driver);

        return $strategy->getCreateTableDdl($connection, $tableName);
    }

    /**
     * Get table metadata (estimated row count and size in MB).
     *
     * @return array{row_count: ?int, size_mb: ?float}
     */
    public function getTableMetadata(Connection $connection, string $tableName): array
    {
        $driver = $this->resolveDriverFromConnection($connection);
        $strategy = $this->strategyFactory->makeSchemaStrategy($driver);

        return $strategy->getTableMetadata($connection, $tableName);
    }

    /**
     * Get the query timeout strategy for a datasource.
     */
    public function getTimeoutStrategy(Datasource $datasource): QueryTimeoutStrategy
    {
        $components = $this->dsnParser->parse($datasource->dsn);
        $driver = $components->getDatabaseDriver();

        return $this->strategyFactory->makeTimeoutStrategy($driver);
    }

    /**
     * Get the schema introspection strategy for a datasource.
     */
    public function getSchemaStrategy(Datasource $datasource): SchemaIntrospectionStrategy
    {
        $components = $this->dsnParser->parse($datasource->dsn);
        $driver = $components->getDatabaseDriver();

        return $this->strategyFactory->makeSchemaStrategy($driver);
    }

    /**
     * Get the DatabaseDriver for a datasource.
     */
    public function getDriver(Datasource $datasource): DatabaseDriver
    {
        $components = $this->dsnParser->parse($datasource->dsn);

        return $components->getDatabaseDriver();
    }

    /**
     * Extract database/schema name from DSN.
     */
    public function databaseNameFromDsn(string $dsn): string
    {
        $components = $this->dsnParser->parse($dsn);

        return $components->database ?? '';
    }

    /**
     * Apply driver-specific configuration options.
     */
    private function applyDriverConfig(array &$config, DatabaseDriver $driver): void
    {
        match ($driver) {
            DatabaseDriver::MySQL => $this->applyMysqlConfig($config),
            DatabaseDriver::PostgreSQL => $this->applyPostgresConfig($config),
        };
    }

    /**
     * Apply MySQL-specific connection configuration.
     */
    private function applyMysqlConfig(array &$config): void
    {
        $config['charset'] = 'utf8mb4';
        $config['collation'] = 'utf8mb4_unicode_ci';
        $config['strict'] = true;
        $config['engine'] = null;
        $config['options'][\Pdo\Mysql::ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
    }

    /**
     * Apply PostgreSQL-specific connection configuration.
     */
    private function applyPostgresConfig(array &$config): void
    {
        $config['charset'] = 'utf8';
        $config['schema'] = 'public';
        $config['sslmode'] = 'prefer';
    }

    /**
     * Apply post-connect settings for driver-specific session configuration.
     * PostgreSQL requires a session statement for read-only mode since it lacks
     * an equivalent to MySQL's Pdo\Mysql::ATTR_INIT_COMMAND.
     */
    private function applyPostConnectSettings(Connection $connection, string $driver): void
    {
        if ($driver === DatabaseDriver::PostgreSQL->value) {
            $connection->statement('SET default_transaction_read_only = on');
        }
    }

    /**
     * Resolve the DatabaseDriver from a connection instance.
     */
    private function resolveDriverFromConnection(Connection $connection): DatabaseDriver
    {
        $driverName = $connection->getDriverName();

        return $this->strategyFactory->resolveDriver($driverName);
    }

    /**
     * Create a temporary connection and return its name.
     */
    public function createTemporaryConnection(Datasource $datasource): string
    {
        $connectionName = self::CONNECTION_PREFIX.uniqid();
        $config = $this->makeConfigFromDatasource($datasource);

        // Add the connection to the database config
        config([
            "database.connections.{$connectionName}" => $config,
        ]);

        // Test the connection and apply post-connect settings
        try {
            $connection = DB::connection($connectionName);
            $connection->getPdo();
            $this->applyPostConnectSettings($connection, $config['driver'] ?? 'mysql');
        } catch (Exception $e) {
            $this->purgeConnection($connectionName);
            throw new DatabaseConnectionException('Failed to establish connection: '.$e->getMessage(), $e);
        }

        return $connectionName;
    }

    /**
     * Purge a temporary connection and its config.
     */
    public function purgeConnection(string $connectionName): void
    {
        try {
            DB::connection($connectionName)->disconnect();
        } catch (Exception $e) {
            Log::warning("Failed to disconnect temporary connection {$connectionName}: ".$e->getMessage());
        }

        // Remove from config
        $connections = config('database.connections', []);
        unset($connections[$connectionName]);
        config([
            'database.connections' => $connections,
        ]);
    }
}
