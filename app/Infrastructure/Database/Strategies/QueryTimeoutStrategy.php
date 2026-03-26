<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\Strategies;

use Illuminate\Database\Connection;

/**
 * Strategy for setting query execution timeouts in a driver-specific way.
 */
interface QueryTimeoutStrategy
{
    /**
     * Set the query execution timeout on the given connection.
     *
     * @param Connection $connection The database connection
     * @param int $seconds Timeout in seconds
     */
    public function setTimeout(Connection $connection, int $seconds): void;
}
