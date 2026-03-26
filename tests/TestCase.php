<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $configCachePath = __DIR__.'/../bootstrap/cache/config.php';
        if (file_exists($configCachePath)) {
            unlink($configCachePath);
        }

        $dbName = env('DB_DATABASE');
        $appEnv = env('APP_ENV');

        // Check if we're in a test environment
        if ($appEnv !== 'test') {
            echo "ERROR: Tests must run with APP_ENV=test\n";
            echo "Current APP_ENV: $appEnv\n";
            echo "Use: APP_ENV=test vendor/bin/phpunit\n";
            echo "Or: ./run-tests.sh\n";
            exit(1);
        }

        // Additional safeguard: ensure database name contains _test (skip for SQLite :memory:)
        if ($dbName !== ':memory:' && strpos($dbName, '_test') === false) {
            echo "ERROR: Database name must contain '_test' for safety\n";
            echo "Current DB_DATABASE: $dbName\n";
            echo "Expected: something_test\n";
            echo "Use: ./run-tests.sh\n";
            exit(1);
        }

        parent::setUp();
    }

    protected function beforeRefreshingDatabase()
    {
        $dbName = config('database.connections.mysql.database');

        // Additional safeguard: ensure database name contains _test
        if (strpos($dbName, '_test') === false) {
            echo "ERROR: Database name must contain '_test' for safety\n";
            echo "Current database: $dbName\n";
            echo "Expected: something_test\n";
            echo "Use: ./run-tests.sh\n";
            exit(1);
        }

        // Auto-create the test database if it doesn't exist
        $config = config('database.connections.'.config('database.default'));
        $pdo = new \PDO(
            "mysql:host={$config['host']};port={$config['port']}",
            $config['username'],
            $config['password'],
        );
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName`");
    }
}
