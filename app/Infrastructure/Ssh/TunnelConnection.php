<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

/**
 * Represents an active SSH tunnel connection.
 *
 * Returned by SshTunnelClient to inform DynamicDatabaseConnector
 * which host/port to use for the proxied database connection.
 */
readonly class TunnelConnection
{
    /**
     * @param string $host The tunnel manager host (e.g. 'threadql-ssh-tunnel')
     * @param int    $port The local port allocated by the tunnel manager
     */
    public function __construct(
        public string $host,
        public int $port,
    ) {
    }
}
