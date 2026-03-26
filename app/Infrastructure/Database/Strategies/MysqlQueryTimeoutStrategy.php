<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use Illuminate\Database\Connection;

/**
 * MySQL-specific query timeout implementation using MAX_EXECUTION_TIME.
 */
class MysqlQueryTimeoutStrategy implements QueryTimeoutStrategy
{
    /**
     * Set query timeout using MySQL's MAX_EXECUTION_TIME session variable.
     *
     * @param int $seconds Timeout in seconds (converted to milliseconds for MySQL)
     */
    public function setTimeout(Connection $connection, int $seconds): void
    {
        $connection->statement('SET SESSION MAX_EXECUTION_TIME = '.($seconds * 1000));
    }
}
