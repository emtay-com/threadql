<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

/**
 * Supported database drivers for tenant datasources.
 */
enum DatabaseDriver: string
{
    case MySQL = 'mysql';
    case PostgreSQL = 'pgsql';

    /**
     * Get the default port for this driver.
     */
    public function defaultPort(): int
    {
        return match ($this) {
            self::MySQL => 3306,
            self::PostgreSQL => 5432,
        };
    }

    /**
     * Get the display name for this driver.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::MySQL => 'MySQL 8',
            self::PostgreSQL => 'PostgreSQL',
        };
    }

    /**
     * Get the SQL dialect identifier for prompt templates.
     */
    public function sqlDialect(): string
    {
        return match ($this) {
            self::MySQL => 'MySQL 8',
            self::PostgreSQL => 'PostgreSQL',
        };
    }
}
