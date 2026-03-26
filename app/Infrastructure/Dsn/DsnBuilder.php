<?php

declare(strict_types=1);

namespace App\Infrastructure\Dsn;

use InvalidArgumentException;

/**
 * Builds DSN strings from component parts with proper escaping.
 *
 * Produces URL format: mysql://user:pass@host:port/dbname
 */
class DsnBuilder
{
    private string $driver = 'mysql';

    private ?string $host = null;

    private ?int $port = null;

    private ?string $database = null;

    private ?string $username = null;

    private ?string $password = null;

    private ?string $unixSocket = null;

    /**
     * Set the database driver.
     */
    public function driver(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Set the host.
     */
    public function host(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    /**
     * Set the port.
     */
    public function port(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    /**
     * Set the database name.
     */
    public function database(string $database): self
    {
        $this->database = $database;

        return $this;
    }

    /**
     * Set the username.
     */
    public function username(string $username): self
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Set the password.
     */
    public function password(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Set the Unix socket path.
     */
    public function unixSocket(string $socket): self
    {
        $this->unixSocket = $socket;

        return $this;
    }

    /**
     * Build the DSN string.
     */
    public function build(): string
    {
        $this->validate();

        if ($this->unixSocket !== null) {
            return $this->buildSocketDsn();
        }

        return $this->buildTcpDsn();
    }

    /**
     * Build a TCP-based DSN (URL format).
     */
    private function buildTcpDsn(): string
    {
        $dsn = $this->driver.'://';

        // Add credentials
        $dsn .= $this->encodeComponent($this->username);
        if ($this->password !== null && $this->password !== '') {
            $dsn .= ':'.$this->encodeComponent($this->password);
        }

        // Add host and port
        $dsn .= '@'.$this->host;
        if ($this->port !== null) {
            $dsn .= ':'.$this->port;
        }

        // Add database
        $dsn .= '/'.$this->encodeComponent($this->database);

        return $dsn;
    }

    /**
     * Build a socket-based DSN (key-value format).
     */
    private function buildSocketDsn(): string
    {
        $parts = ['unix_socket='.$this->unixSocket, 'dbname='.$this->database];

        if ($this->username !== null) {
            $parts[] = 'user='.$this->username;
        }

        if ($this->password !== null && $this->password !== '') {
            $parts[] = 'password='.$this->password;
        }

        return $this->driver.':'.implode(';', $parts);
    }

    /**
     * Validate that all required components are present.
     */
    private function validate(): void
    {
        if ($this->database === null || $this->database === '') {
            throw new InvalidArgumentException('Database name is required');
        }

        if ($this->username === null || $this->username === '') {
            throw new InvalidArgumentException('Username is required');
        }

        if ($this->unixSocket === null && ($this->host === null || $this->host === '')) {
            throw new InvalidArgumentException('Host is required for TCP connections');
        }
    }

    /**
     * URL-encode a component for safe inclusion in DSN.
     *
     * Uses rawurlencode to encode special characters that could
     * break DSN parsing (: @ / and other reserved characters).
     */
    private function encodeComponent(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return rawurlencode($value);
    }

    /**
     * Create a new builder instance from DsnComponents.
     */
    public static function fromComponents(DsnComponents $components): self
    {
        $builder = new self();

        $builder->driver = $components->driver;

        if ($components->host !== null) {
            $builder->host = $components->host;
        }

        if ($components->port !== null) {
            $builder->port = $components->port;
        }

        if ($components->database !== null) {
            $builder->database = $components->database;
        }

        if ($components->username !== null) {
            $builder->username = $components->username;
        }

        if ($components->password !== null) {
            $builder->password = $components->password;
        }

        if ($components->unixSocket !== null) {
            $builder->unixSocket = $components->unixSocket;
        }

        return $builder;
    }
}
