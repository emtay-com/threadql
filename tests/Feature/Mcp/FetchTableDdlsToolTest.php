<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\FetchTableDdlsTool;
use App\Models\Query;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Test the fetch_table_ddls MCP tool functionality
 */
class FetchTableDdlsToolTest extends TestCase
{
    private FetchTableDdlsTool $tool;

    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the queue to prevent jobs from actually being executed
        Queue::fake();

        $this->tool = app(FetchTableDdlsTool::class);

        // Create test data
        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => '1234567890.123456',
        ]);
        $this->query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'raw_text' => 'test query',
        ]);

        // Create test tables
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'priority_table',
            'priority' => 1,
            'ddl_sql' => 'CREATE TABLE priority_table (id INT PRIMARY KEY);',
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'non_priority_table',
            'priority' => 0,
            'row_count' => 5000,
            'size_mb' => 2.5,
            'ddl_sql' => 'CREATE TABLE non_priority_table (id INT PRIMARY KEY, name VARCHAR(255));',
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'another_non_priority',
            'priority' => 0,
            'row_count' => 10000,
            'size_mb' => 8.125,
            'ddl_sql' => 'CREATE TABLE another_non_priority (id INT PRIMARY KEY, value DECIMAL(10,2));',
        ]);
    }

    /**
     * Helper to call the tool and parse the response
     */
    private function callTool(int $queryId, string $tables): array
    {
        $request = new Request([
            'query_id' => $queryId,
            'tables' => $tables,
        ]);

        $response = $this->tool->handle($request);
        $content = $response->content()
            ->toArray();

        return json_decode($content['text'], true);
    }

    /**
     * Test successful DDL fetch for non-priority tables
     */
    public function test_fetch_ddls_success(): void
    {
        $result = $this->callTool($this->query->id, 'non_priority_table,another_non_priority');

        $this->assertTrue($result['ok']);
        $this->assertEquals($this->tenant->id, $result['tenant_id']);
        $this->assertEquals($this->query->id, $result['query_id']);
        $this->assertEquals(['non_priority_table', 'another_non_priority'], $result['requested']);
        $this->assertCount(2, $result['found']);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['skipped']);
        $this->assertFalse($result['truncated']);

        // Check found tables
        $foundTables = collect($result['found']);
        $nonPriorityTable = $foundTables->firstWhere('table', 'non_priority_table');
        $this->assertNotNull($nonPriorityTable);
        $this->assertEquals(0, $nonPriorityTable['priority']);
        $this->assertStringContainsString('CREATE TABLE non_priority_table', $nonPriorityTable['ddl']);
        $this->assertFalse($nonPriorityTable['ddl_truncated']);
        $this->assertEquals(5000, $nonPriorityTable['row_count']);
        $this->assertEquals(2.5, $nonPriorityTable['size_mb']);
    }

    /**
     * Test that priority tables can also be fetched
     */
    public function test_priority_tables_are_also_fetched(): void
    {
        $result = $this->callTool($this->query->id, 'priority_table,non_priority_table');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['priority_table', 'non_priority_table'], $result['requested']);
        $this->assertCount(2, $result['found']);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['skipped']);

        $foundTables = collect($result['found']);
        $this->assertNotNull($foundTables->firstWhere('table', 'priority_table'));
        $this->assertNotNull($foundTables->firstWhere('table', 'non_priority_table'));
    }

    /**
     * Test handling of missing tables
     */
    public function test_missing_tables_handled(): void
    {
        $result = $this->callTool($this->query->id, 'non_priority_table,missing_table');

        $this->assertTrue($result['ok']);
        $this->assertEquals(['non_priority_table', 'missing_table'], $result['requested']);
        $this->assertCount(1, $result['found']); // Only existing table
        $this->assertEquals(['missing_table'], $result['missing']);
        $this->assertEmpty($result['skipped']);
    }

    /**
     * Test DDL truncation when size limit is exceeded
     */
    public function test_ddl_truncation(): void
    {
        // Create a table with very long DDL
        $longDdl = 'CREATE TABLE long_ddl_table ('.str_repeat('column_name VARCHAR(255), ', 100).'id INT);';
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'long_ddl_table',
            'priority' => 0,
            'ddl_sql' => $longDdl,
        ]);

        // Set a very low max DDL chars limit for this test
        config([
            'llm.max_ddl_chars' => 50,
        ]);

        $result = $this->callTool($this->query->id, 'long_ddl_table');

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['found']);

        $foundTable = $result['found'][0];
        $this->assertEquals('long_ddl_table', $foundTable['table']);
        $this->assertTrue($foundTable['ddl_truncated']);
        $this->assertLessThan(strlen($longDdl), strlen($foundTable['ddl']));
    }

    /**
     * Test table count truncation
     */
    public function test_table_count_truncation(): void
    {
        // Create many tables
        for ($i = 0; $i < 25; $i++) {
            Table::factory()->create([
                'tenant_id' => $this->tenant->id,
                'name' => "table_{$i}",
                'priority' => 0,
                'ddl_sql' => "CREATE TABLE table_{$i} (id INT);",
            ]);
        }

        // Set low max tables limit
        config([
            'llm.max_ddl_tables_per_call' => 5,
        ]);

        $tableNames = collect(range(0, 24))
            ->map(fn ($i) => "table_{$i}")
            ->implode(',');
        $result = $this->callTool($this->query->id, $tableNames);

        $this->assertTrue($result['ok']);
        $this->assertCount(5, $result['found']); // Limited to max
        $this->assertTrue($result['truncated']); // Should be truncated
    }

    /**
     * Test error handling for invalid query ID
     */
    public function test_invalid_query_id_error(): void
    {
        $result = $this->callTool(99999, 'non_priority_table');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Query not found', $result['error']);
    }

    /**
     * Test error handling for invalid tables parameter
     */
    public function test_invalid_tables_error(): void
    {
        $result = $this->callTool($this->query->id, '');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid tables provided', $result['error']);
    }

    /**
     * Test table name parsing and deduplication
     */
    public function test_table_name_parsing_and_deduplication(): void
    {
        $result = $this->callTool(
            $this->query->id,
            'non_priority_table, NON_PRIORITY_TABLE , another_non_priority, non_priority_table '
        );

        $this->assertTrue($result['ok']);
        $this->assertEquals(['non_priority_table', 'another_non_priority'], $result['requested']);
        $this->assertCount(2, $result['found']); // Deduplicated
    }
}
