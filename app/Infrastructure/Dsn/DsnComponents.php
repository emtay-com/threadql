<?php

declare(strict_types=1);

namespace App\Infrastructure\Dsn;

use App\Infrastructure\Database\DatabaseDriver;

/**
 * Data transfer object representing DSN components.
 */
readonly class DsnComponents
{
    public function __construct(
        public string $driver = 'mysql',
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?string $username = null,
        public ?string $password = null,
        public ?string $unixSocket = null,
    ) {
    }

    /**
     * Check if this DSN uses a Unix socket connection.
     */
    public function isSocketConnection(): bool
    {
        return $this->unixSocket !== null;
    }

    /**
     * Get the effective port based on the driver.
     */
    public function getEffectivePort(): int
    {
        if ($this->port !== null) {
            return $this->port;
        }

        $databaseDriver = DatabaseDriver::tryFrom($this->driver);

        return $databaseDriver?->defaultPort() ?? 3306;
    }

    /**
     * Get the effective host (default 127.0.0.1).
     */
    public function getEffectiveHost(): string
    {
        return $this->host ?? '127.0.0.1';
    }

    /**
     * Get the DatabaseDriver enum for this DSN.
     */
    public function getDatabaseDriver(): DatabaseDriver
    {
        return DatabaseDriver::tryFrom($this->driver) ?? DatabaseDriver::MySQL;
    }
}
