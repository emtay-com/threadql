<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database\Strategies;

use App\Exceptions\TableNotFoundException;
use App\Infrastructure\Database\Strategies\PostgresSchemaStrategy;
use Illuminate\Database\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostgresSchemaStrategyTest extends TestCase
{
    private PostgresSchemaStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new PostgresSchemaStrategy();
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
        $connection->shouldReceive('select')
            ->once()
            ->andReturn([
                [
                    'table_name' => 'users',
                ],
                [
                    'table_name' => 'posts',
                ],
                [
                    'table_name' => 'comments',
                ],
            ]);

        $tables = $this->strategy->listTables($connection);

        $this->assertEquals(['users', 'posts', 'comments'], $tables);
    }

    #[Test]
    public function it_returns_empty_array_when_no_tables(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->andReturn([]);

        $tables = $this->strategy->listTables($connection);

        $this->assertEquals([], $tables);
    }

    #[Test]
    public function it_gets_create_table_ddl_from_metadata(): void
    {
        $connection = Mockery::mock(Connection::class);

        // Column query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'information_schema.columns')
                    && $params === ['public', 'users'];
            })
            ->andReturn([
                (object) [
                    'column_name' => 'id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => "nextval('users_id_seq'::regclass)",
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
                (object) [
                    'column_name' => 'name',
                    'data_type' => 'character varying',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => 255,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
                (object) [
                    'column_name' => 'email',
                    'data_type' => 'character varying',
                    'is_nullable' => 'YES',
                    'column_default' => null,
                    'character_maximum_length' => 255,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
            ]);

        // Constraints query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'table_constraints')
                    && $params === ['public', 'users'];
            })
            ->andReturn([
                (object) [
                    'constraint_type' => 'PRIMARY KEY',
                    'constraint_name' => 'users_pkey',
                    'column_name' => 'id',
                    'foreign_table_name' => null,
                    'foreign_column_name' => null,
                ],
            ]);

        // Indexes query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_class')
                    && $params === ['public', 'users'];
            })
            ->andReturn([]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'users');

        $this->assertStringContainsString('CREATE TABLE users', $ddl);
        $this->assertStringContainsString('id integer NOT NULL', $ddl);
        $this->assertStringContainsString('name character varying(255) NOT NULL', $ddl);
        $this->assertStringContainsString('email character varying(255)', $ddl);
        $this->assertStringContainsString('PRIMARY KEY (id)', $ddl);
    }

    #[Test]
    public function it_throws_exception_when_table_not_found(): void
    {
        $connection = Mockery::mock(Connection::class);

        // Column query returns empty - table doesn't exist
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'information_schema.columns')
                    && $params === ['public', 'nonexistent'];
            })
            ->andReturn([]);

        $this->expectException(TableNotFoundException::class);
        $this->expectExceptionMessage("Table 'nonexistent' not found or not accessible");

        $this->strategy->getCreateTableDdl($connection, 'nonexistent');
    }

    #[Test]
    public function it_includes_foreign_keys_in_ddl(): void
    {
        $connection = Mockery::mock(Connection::class);

        // Column query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'information_schema.columns')
                    && $params === ['public', 'posts'];
            })
            ->andReturn([
                (object) [
                    'column_name' => 'id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
                (object) [
                    'column_name' => 'user_id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
            ]);

        // Constraints query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'table_constraints')
                    && $params === ['public', 'posts'];
            })
            ->andReturn([
                (object) [
                    'constraint_type' => 'PRIMARY KEY',
                    'constraint_name' => 'posts_pkey',
                    'column_name' => 'id',
                    'foreign_table_name' => null,
                    'foreign_column_name' => null,
                ],
                (object) [
                    'constraint_type' => 'FOREIGN KEY',
                    'constraint_name' => 'posts_user_id_fkey',
                    'column_name' => 'user_id',
                    'foreign_table_name' => 'users',
                    'foreign_column_name' => 'id',
                ],
            ]);

        // Indexes query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_class')
                    && $params === ['public', 'posts'];
            })
            ->andReturn([]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'posts');

        $this->assertStringContainsString('PRIMARY KEY (id)', $ddl);
        $this->assertStringContainsString(
            'CONSTRAINT posts_user_id_fkey FOREIGN KEY (user_id) REFERENCES users (id)',
            $ddl
        );
    }

    #[Test]
    public function it_includes_unique_constraints_in_ddl(): void
    {
        $connection = Mockery::mock(Connection::class);

        // Column query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'information_schema.columns')
                    && $params === ['public', 'users'];
            })
            ->andReturn([
                (object) [
                    'column_name' => 'id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
                (object) [
                    'column_name' => 'email',
                    'data_type' => 'character varying',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => 255,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
            ]);

        // Constraints query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'table_constraints')
                    && $params === ['public', 'users'];
            })
            ->andReturn([
                (object) [
                    'constraint_type' => 'PRIMARY KEY',
                    'constraint_name' => 'users_pkey',
                    'column_name' => 'id',
                    'foreign_table_name' => null,
                    'foreign_column_name' => null,
                ],
                (object) [
                    'constraint_type' => 'UNIQUE',
                    'constraint_name' => 'users_email_unique',
                    'column_name' => 'email',
                    'foreign_table_name' => null,
                    'foreign_column_name' => null,
                ],
            ]);

        // Indexes query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_class')
                    && $params === ['public', 'users'];
            })
            ->andReturn([]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'users');

        $this->assertStringContainsString('PRIMARY KEY (id)', $ddl);
        $this->assertStringContainsString('CONSTRAINT users_email_unique UNIQUE (email)', $ddl);
    }

    #[Test]
    public function it_gets_table_metadata_from_pg_class(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_total_relation_size')
                    && $params === ['public', 'users', 'r'];
            })
            ->andReturn([
                (object) [
                    'row_count' => 25000,
                    'size_mb' => 8.1234,
                ],
            ]);

        $metadata = $this->strategy->getTableMetadata($connection, 'users');

        $this->assertEquals(25000, $metadata['row_count']);
        $this->assertEquals(8.1234, $metadata['size_mb']);
    }

    #[Test]
    public function it_returns_nulls_when_table_metadata_not_found(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_total_relation_size')
                    && $params === ['public', 'nonexistent', 'r'];
            })
            ->andReturn([]);

        $metadata = $this->strategy->getTableMetadata($connection, 'nonexistent');

        $this->assertNull($metadata['row_count']);
        $this->assertNull($metadata['size_mb']);
    }

    #[Test]
    public function it_clamps_negative_row_count_to_zero(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')
            ->once()
            ->andReturn([
                (object) [
                    'row_count' => -1,
                    'size_mb' => 0.0,
                ],
            ]);

        $metadata = $this->strategy->getTableMetadata($connection, 'empty_table');

        $this->assertEquals(0, $metadata['row_count']);
    }

    #[Test]
    public function it_includes_indexes_in_ddl(): void
    {
        $connection = Mockery::mock(Connection::class);

        // Column query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'information_schema.columns')
                    && $params === ['public', 'orders'];
            })
            ->andReturn([
                (object) [
                    'column_name' => 'id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
                (object) [
                    'column_name' => 'customer_id',
                    'data_type' => 'integer',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => null,
                    'numeric_precision' => 32,
                    'numeric_scale' => 0,
                ],
                (object) [
                    'column_name' => 'status',
                    'data_type' => 'character varying',
                    'is_nullable' => 'NO',
                    'column_default' => null,
                    'character_maximum_length' => 50,
                    'numeric_precision' => null,
                    'numeric_scale' => null,
                ],
            ]);

        // Constraints query
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'table_constraints')
                    && $params === ['public', 'orders'];
            })
            ->andReturn([
                (object) [
                    'constraint_type' => 'PRIMARY KEY',
                    'constraint_name' => 'orders_pkey',
                    'column_name' => 'id',
                    'foreign_table_name' => null,
                    'foreign_column_name' => null,
                ],
            ]);

        // Indexes query - returns non-primary indexes
        $connection->shouldReceive('select')
            ->once()
            ->withArgs(function ($sql, $params) {
                return str_contains($sql, 'pg_class')
                    && $params === ['public', 'orders'];
            })
            ->andReturn([
                (object) [
                    'index_name' => 'idx_orders_customer_id',
                    'is_unique' => false,
                    'column_name' => 'customer_id',
                ],
                (object) [
                    'index_name' => 'idx_orders_status_unique',
                    'is_unique' => true,
                    'column_name' => 'status',
                ],
            ]);

        $ddl = $this->strategy->getCreateTableDdl($connection, 'orders');

        $this->assertStringContainsString('PRIMARY KEY (id)', $ddl);
        $this->assertStringContainsString('-- INDEX idx_orders_customer_id (customer_id)', $ddl);
        $this->assertStringContainsString('-- UNIQUE INDEX idx_orders_status_unique (status)', $ddl);
    }
}
