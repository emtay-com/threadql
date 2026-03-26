<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use Illuminate\Database\Connection;

/**
 * PostgreSQL-specific query timeout implementation using statement_timeout.
 */
class PostgresQueryTimeoutStrategy implements QueryTimeoutStrategy
{
    /**
     * Set query timeout using PostgreSQL's statement_timeout setting.
     *
     * @param int $seconds Timeout in seconds (converted to milliseconds for PostgreSQL)
     */
    public function setTimeout(Connection $connection, int $seconds): void
    {
        $milliseconds = $seconds * 1000;
        $connection->unprepared("SET statement_timeout = {$milliseconds}");
    }
}
