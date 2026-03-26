<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Infrastructure\Database\Strategies\MysqlQueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\MysqlSchemaStrategy;
use App\Infrastructure\Database\Strategies\PostgresQueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\PostgresSchemaStrategy;
use App\Infrastructure\Database\Strategies\QueryTimeoutStrategy;
use App\Infrastructure\Database\Strategies\SchemaIntrospectionStrategy;
use InvalidArgumentException;

/**
 * Factory for resolving database-specific strategy implementations.
 */
class DatabaseStrategyFactory
{
    /**
     * Get the query timeout strategy for the given driver.
     */
    public function makeTimeoutStrategy(DatabaseDriver $driver): QueryTimeoutStrategy
    {
        return match ($driver) {
            DatabaseDriver::MySQL => new MysqlQueryTimeoutStrategy(),
            DatabaseDriver::PostgreSQL => new PostgresQueryTimeoutStrategy(),
        };
    }

    /**
     * Get the schema introspection strategy for the given driver.
     */
    public function makeSchemaStrategy(DatabaseDriver $driver): SchemaIntrospectionStrategy
    {
        return match ($driver) {
            DatabaseDriver::MySQL => new MysqlSchemaStrategy(),
            DatabaseDriver::PostgreSQL => new PostgresSchemaStrategy(),
        };
    }

    /**
     * Resolve a DatabaseDriver from a string driver name.
     */
    public function resolveDriver(string $driverName): DatabaseDriver
    {
        $driver = DatabaseDriver::tryFrom($driverName);

        if ($driver === null) {
            throw new InvalidArgumentException("Unsupported database driver: {$driverName}");
        }

        return $driver;
    }
}
