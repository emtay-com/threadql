<?php

declare(strict_types=1);

namespace Tests\Unit\Prompt;

use App\Enums\QueryStatus;
use App\Models\Query;
use App\Models\Tenant;
use App\Services\Llm\PromptBuilder;
use Tests\TestCase;

/**
 * Test the PromptBuilder recent queries functionality
 */
class PromptBuilderRecentQueriesTest extends TestCase
{
    private PromptBuilder $promptBuilder;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promptBuilder = app(PromptBuilder::class);
        $this->tenant = Tenant::factory()->create();
    }

    /**
     * Test buildRecentQueriesContext returns null when no recent queries
     */
    public function test_build_recent_queries_returns_null_when_no_queries(): void
    {
        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNull($result);
    }

    /**
     * Test buildRecentQueriesContext returns null when config is disabled
     */
    public function test_build_recent_queries_returns_null_when_disabled(): void
    {
        config([
            'llm.include_recent_queries' => false,
        ]);

        // Create some queries
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'test question',
            'sql_text' => 'SELECT * FROM test',
            'parameters' => json_encode([
                'param' => 'value',
            ]),
        ]);

        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNull($result);
    }

    /**
     * Test buildRecentQueriesContext returns formatted recent queries
     */
    public function test_build_recent_queries_returns_formatted_queries(): void
    {
        // Create recent queries
        $query1 = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'How many users?',
            'sql_text' => 'SELECT COUNT(*) FROM users',
            'parameters' => json_encode([
                'limit' => 100,
            ]),
            'created_at' => now()
                ->subMinutes(5),
        ]);

        $query2 = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'Top products',
            'sql_text' => 'SELECT * FROM products ORDER BY sales DESC LIMIT 10',
            'parameters' => json_encode([
                'limit' => 10,
            ]),
            'created_at' => now()
                ->subMinutes(10),
        ]);

        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('## Recent queries (last 5)', $result);
        $this->assertStringContainsString('question: How many users?', $result);
        $this->assertStringContainsString('sql: SELECT COUNT(*) FROM users', $result);
        $this->assertStringContainsString('parameters: {"limit":100}', $result);
        $this->assertStringContainsString('question: Top products', $result);
        $this->assertStringContainsString('sql: SELECT * FROM products ORDER BY sales DESC LIMIT 10', $result);
        $this->assertStringContainsString('parameters: {"limit":10}', $result);
    }

    /**
     * Test buildRecentQueriesContext excludes queries without required fields
     */
    public function test_build_recent_queries_excludes_incomplete_queries(): void
    {
        // Create complete query
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'Complete question',
            'sql_text' => 'SELECT * FROM complete',
            'parameters' => json_encode([
                'param' => 'value',
            ]),
        ]);

        // Create incomplete queries (with empty strings instead of null)
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => '', // Empty question
            'sql_text' => 'SELECT * FROM incomplete1',
        ]);

        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'Incomplete SQL',
            'sql_text' => '', // Empty SQL
        ]);

        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNotNull($result);
        $this->assertStringContainsString('question: Complete question', $result);
        $this->assertStringNotContainsString('Incomplete SQL', $result);
        $this->assertStringNotContainsString('incomplete1', $result);
    }

    /**
     * Test buildRecentQueriesContext respects size limits
     */
    public function test_build_recent_queries_respects_size_limits(): void
    {
        // Create a thread for the queries
        $thread = \App\Models\Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => '1234567890.123456',
        ]);

        // Create queries with large content
        for ($i = 1; $i <= 10; $i++) {
            Query::factory()->create([
                'tenant_id' => $this->tenant->id,
                'thread_id' => $thread->id,
                'status' => QueryStatus::DONE->value,
                'raw_text' => 'Question '.$i.' with lots of content that should exceed the size limit when combined',
                'sql_text' => 'SELECT * FROM table_'.$i.' WHERE column = :param_'.$i,
                'parameters' => json_encode([
                    'param_'.$i => 'value_'.$i,
                ]),
            ]);
        }

        // Set a higher size limit to accommodate the query content
        config([
            'llm.ddl_context.max_size_bytes' => 2000,
        ]); // Increased from 100 to allow for reasonable content
        config([
            'llm.include_recent_queries' => true,
        ]);

        // Debug: Check if queries exist
        $queries = Query::where('tenant_id', $this->tenant->id)->where('status', QueryStatus::DONE->value)->get();
        $this->assertGreaterThan(0, $queries->count(), 'Should have queries in database');

        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNotNull($result, 'Result should not be null');
        // Should contain some queries but not all due to size limit
        $this->assertStringContainsString('Question', $result);
        $this->assertLessThan(
            10,
            substr_count($result, 'question:'),
            'Should contain fewer than 10 queries due to size limit'
        );
    }

    /**
     * Test buildRecentQueriesContext only includes completed queries
     */
    public function test_build_recent_queries_only_includes_completed_queries(): void
    {
        // Create completed query
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::DONE->value,
            'raw_text' => 'Completed query',
            'sql_text' => 'SELECT * FROM completed',
        ]);

        // Create non-completed queries
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::EXECUTING->value,
            'raw_text' => 'Executing query',
            'sql_text' => 'SELECT * FROM executing',
        ]);

        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => QueryStatus::ERROR->value,
            'raw_text' => 'Error query',
            'sql_text' => 'SELECT * FROM error',
        ]);

        $result = $this->promptBuilder->buildRecentQueriesContext($this->tenant);

        $this->assertNotNull($result);
        $this->assertStringContainsString('Completed query', $result);
        $this->assertStringNotContainsString('Executing query', $result);
        $this->assertStringNotContainsString('Error query', $result);
    }

    /**
     * Test buildOtherTablesContext returns null when no tables
     */
    public function test_build_other_tables_returns_null_when_no_tables(): void
    {
        $result = $this->promptBuilder->buildOtherTablesContext($this->tenant);

        $this->assertNull($result);
    }

    /**
     * Test buildOtherTablesContext returns formatted table list
     */
    public function test_build_other_tables_returns_formatted_list(): void
    {
        // Create tables (will be created in setUp or test setup)
        \App\Models\Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'zebra_table',
            'priority' => 0,
        ]);

        \App\Models\Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'alpha_table',
            'priority' => 0,
        ]);

        // Create priority table (should be excluded)
        \App\Models\Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'priority_table',
            'priority' => 1,
        ]);

        $result = $this->promptBuilder->buildOtherTablesContext($this->tenant);

        $this->assertNotNull($result);
        $this->assertStringStartsWith('## Other tables available (no DDL included)', $result);
        // Should be sorted alphabetically
        $this->assertStringContainsString('alpha_table, zebra_table', $result);
        $this->assertStringNotContainsString('priority_table', $result);
    }
}
