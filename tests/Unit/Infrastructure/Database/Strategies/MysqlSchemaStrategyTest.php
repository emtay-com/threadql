<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database\Strategies;

use App\Exceptions\TableNotFoundException;
use App\Infrastructure\Database\Strategies\MysqlSchemaStrategy;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MysqlSchemaStrategyTest extends TestCase
{
    private MysqlSchemaStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new MysqlSchemaStrategy();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_lists_tables_from_information_schema(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('testdb');
        $connection->shouldReceive('select')
            ->andReturn([
                [
                    'TABLE_NAME' => 'users',
                ],
                [
                    'TABLE_NAME' => 'posts',
                ],
                [
                    'TABLE_NAME' => 'comments',
                ],
            ]);

        $tables = $this->strategy->listTables($connection);

        $this->assertEquals(['users', 'posts', 'comments'], $tables);
    }

    #[Test]
    public function it_returns_empty_array_when_no_tables(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('emptydb');
        $connection->shouldReceive('select')
            ->andReturn([]);

        $tables = $this->strategy->listTables($connection);

        $this->assertEquals([], $tables);
    }

    #[Test]
    public function it_gets_create_table_ddl(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar->wrapTable')
            ->with('users')
            ->andReturn('`users`');
        $connection->shouldReceive('select')
            ->with('SHOW CREATE TABLE `users`')
            ->andReturn([
                [
                    'Create Table' => 'CREATE TABLE `users` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
                ],
            ]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'users');

        $this->assertEquals(
            'CREATE TABLE `users` (`id` int NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB',
            $ddl
        );
    }

    #[Test]
    public function it_handles_object_result_format(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar->wrapTable')
            ->with('users')
            ->andReturn('`users`');

        $result = new \stdClass();
        $result->{'Create Table'} = 'CREATE TABLE `users` (`id` int NOT NULL)';

        $connection->shouldReceive('select')
            ->with('SHOW CREATE TABLE `users`')
            ->andReturn([$result]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'users');

        $this->assertEquals('CREATE TABLE `users` (`id` int NOT NULL)', $ddl);
    }

    #[Test]
    public function it_gets_table_metadata_from_information_schema(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('testdb');
        $connection->shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'TABLE_ROWS' => 50000,
                    'size_mb' => 12.5678,
                ],
            ]);

        $metadata = $this->strategy->getTableMetadata($connection, 'users');

        $this->assertEquals(50000, $metadata['row_count']);
        $this->assertEquals(12.5678, $metadata['size_mb']);
    }

    #[Test]
    public function it_returns_nulls_when_table_metadata_not_found(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')
            ->andReturn('testdb');
        $connection->shouldReceive('select')
            ->once()
            ->andReturn([]);

        $metadata = $this->strategy->getTableMetadata($connection, 'nonexistent');

        $this->assertNull($metadata['row_count']);
        $this->assertNull($metadata['size_mb']);
    }

    #[Test]
    public function it_throws_exception_when_table_not_found(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar->wrapTable')
            ->with('nonexistent')
            ->andReturn('`nonexistent`');
        $connection->shouldReceive('select')
            ->with('SHOW CREATE TABLE `nonexistent`')
            ->andReturn([]);

        $this->expectException(TableNotFoundException::class);
        $this->expectExceptionMessage("Table 'nonexistent' not found or not accessible");

        $this->strategy->getCreateTableDdl($connection, 'nonexistent');
    }
}
