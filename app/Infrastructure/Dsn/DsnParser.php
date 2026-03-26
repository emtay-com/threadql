<?php

declare(strict_types=1);

namespace App\Infrastructure\Dsn;

/**
 * Parses DSN strings into their component parts.
 *
 * Supports two DSN formats:
 * - URL format: driver://user:pass@host:port/dbname (mysql://, pgsql://)
 * - Key-value format: driver:host=127.0.0.1;port=3306;dbname=mydb;user=xxx;password=xxx
 */
class DsnParser
{
    /**
     * Parse a DSN string into its components.
     *
     * @param string $dsn The DSN string to parse
     * @return DsnComponents The parsed components
     */
    public function parse(string $dsn): DsnComponents
    {
        $parts = $this->extractParts($dsn);

        return new DsnComponents(
            driver: $parts['driver'] ?? 'mysql',
            host: $parts['host'] ?? null,
            port: isset($parts['port']) ? (int) $parts['port'] : null,
            database: $parts['dbname'] ?? null,
            username: $parts['username'] ?? null,
            password: $parts['password'] ?? null,
            unixSocket: $parts['unix_socket'] ?? null,
        );
    }

    /**
     * Extract parts from a DSN string.
     *
     * @return array<string, string>
     */
    private function extractParts(string $dsn): array
    {
        $parts = [];
        $driverPattern = '(mysql|pgsql)';

        // Handle driver://user:pass@host:port/db format
        if (preg_match('/^'.$driverPattern.':\/\/([^:]*):([^@]*)@([^:\/]+)(?::(\d+))?\/(.+)$/', $dsn, $matches)) {
            $parts['driver'] = $matches[1];
            $parts['username'] = $this->urlDecode($matches[2]);
            $parts['password'] = $this->urlDecode($matches[3]);
            $parts['host'] = $matches[4];
            if (! empty($matches[5])) {
                $parts['port'] = $matches[5];
            }
            $parts['dbname'] = $this->urlDecode($matches[6]);
        }
        // Handle driver://user@host:port/db format (no password)
        elseif (preg_match('/^'.$driverPattern.':\/\/([^@]+)@([^:\/]+)(?::(\d+))?\/(.+)$/', $dsn, $matches)) {
            $parts['driver'] = $matches[1];
            $parts['username'] = $this->urlDecode($matches[2]);
            $parts['host'] = $matches[3];
            if (! empty($matches[4])) {
                $parts['port'] = $matches[4];
            }
            $parts['dbname'] = $this->urlDecode($matches[5]);
        }
        // Handle driver:host=127.0.0.1;port=3306;dbname=mydb format
        elseif (preg_match('/^'.$driverPattern.':(.+)$/', $dsn, $matches)) {
            $parts['driver'] = $matches[1];
            $params = explode(';', $matches[2]);

            foreach ($params as $param) {
                if (strpos($param, '=') !== false) {
                    [$key, $value] = explode('=', $param, 2);
                    $parts[$key] = $value;
                }
            }

            // Normalize 'user' to 'username' for consistency
            if (isset($parts['user'])) {
                $parts['username'] = $parts['user'];
                unset($parts['user']);
            }
        }

        return $parts;
    }

    /**
     * URL decode a value, handling special characters.
     */
    private function urlDecode(string $value): string
    {
        return rawurldecode($value);
    }
}
