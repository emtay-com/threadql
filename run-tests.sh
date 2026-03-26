#!/bin/bash
set -e

# Force test environment variables
export APP_ENV=test
export DB_DATABASE=threadql_test
export DB_CONNECTION=mysql
export DB_HOST=mysql
export DB_PORT=3306
export DB_USERNAME=root
export DB_PASSWORD=root

# The full suite peaks above PHP's default 128M in this project.
# Allow override (for CI/local tuning) while keeping a safe default.
PHPUNIT_MEMORY_LIMIT="${PHPUNIT_MEMORY_LIMIT:-256M}"

echo "Running tests with test database: $DB_DATABASE"
echo "APP_ENV: $APP_ENV"
echo "PHPUNIT_MEMORY_LIMIT: $PHPUNIT_MEMORY_LIMIT"

# Run PHPUnit directly to ensure configuration is loaded
php -d "memory_limit=${PHPUNIT_MEMORY_LIMIT}" vendor/bin/phpunit "$@"
