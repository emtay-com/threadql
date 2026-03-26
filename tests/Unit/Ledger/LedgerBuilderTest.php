<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Ledger\LedgerBuilder;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Models\ToolCall;
use Carbon\Carbon;
use Tests\TestCase;

class LedgerBuilderTest extends TestCase
{
    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
        ]);
    }

    public function test_build_returns_empty_array_when_no_tool_calls(): void
    {
        $ledgerBuilder = new LedgerBuilder($this->query);
        $ledger = $ledgerBuilder->build();

        $this->assertEquals([], $ledger);
    }

    public function test_build_returns_ledger_lines_for_tool_calls(): void
    {
        // Create some tool calls
        $toolCall1 = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"table_names":"users,orders"}',
            'response_payload' => '{"found":["users"],"missing":["orders"]}',
            'created_at' => Carbon::now()->subMinutes(10),
        ]);

        $toolCall2 = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"sql":"SELECT * FROM users","parameters":{}}',
            'response_payload' => '{"result_kind":"rows","row_count":50}',
            'created_at' => Carbon::now()->subMinutes(5),
        ]);

        $ledgerBuilder = new LedgerBuilder($this->query);
        $ledger = $ledgerBuilder->build();

        $this->assertCount(2, $ledger);
        $this->assertStringContainsString('Tool: fetch_table_ddls', $ledger[0]);
        $this->assertStringContainsString('Tool: run_sql_query', $ledger[1]);
    }

    public function test_build_includes_all_queries_in_thread(): void
    {
        // Create another query in the same thread (create it first so it gets lower ID)
        $otherQuery = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'created_at' => now()
                ->subMinutes(10), // Make it older
        ]);

        // Update the main query to be more recent
        $this->query->update([
            'created_at' => now(),
        ]);

        // Create tool calls for both queries
        $toolCall1 = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $otherQuery->id,
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"table_names":"users"}',
            'response_payload' => '{"found":["users"]}',
        ]);

        $toolCall2 = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"sql":"SELECT * FROM users","parameters":{}}',
            'response_payload' => '{"result_kind":"rows","row_count":50}',
        ]);

        // Build ledger for the more recent query (higher ID)
        $ledgerBuilder = new LedgerBuilder($otherQuery);
        $ledger = $ledgerBuilder->build();

        $this->assertCount(2, $ledger);
        $this->assertStringContainsString('Tool: fetch_table_ddls', $ledger[0]);
        $this->assertStringContainsString('Tool: run_sql_query', $ledger[1]);
    }

    public function test_build_limits_column_count_in_results(): void
    {
        $longColumnList = implode(',', array_map(fn ($i) => "col{$i}", range(1, 20)));

        $toolCall = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"sql":"SELECT * FROM test","parameters":{}}',
            'response_payload' => "{\"result_kind\":\"rows\",\"row_count\":100,\"columns\":\"[{$longColumnList}]\"}",
        ]);

        $ledgerBuilder = new LedgerBuilder($this->query);
        $ledger = $ledgerBuilder->build(maxColumns: 5);

        $this->assertCount(1, $ledger);
        $this->assertStringContainsString('col1,col2,col3,col4,col5', $ledger[0]);
        $this->assertStringContainsString('(+15 more)', $ledger[0]);
    }

    public function test_summarize_tool_call_with_long_args(): void
    {
        $longArgs = str_repeat('a', 4000);

        $toolCall = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'run_sql_query',
            'request_payload' => "{\"sql\":\"{$longArgs}\",\"parameters\":{}}",
            'response_payload' => '{"result_kind":"rows","row_count":10}',
        ]);

        $summary = LedgerBuilder::summarizeToolCall($toolCall, 10, 3000);

        // The ToolCallSummarizer may truncate args to show only keys for long JSON
        $this->assertLessThanOrEqual(3000, strlen($summary['args']));

        // Should contain the key structure when truncated
        if (strlen($summary['args']) < strlen($longArgs)) {
            $this->assertStringContainsString('"sql":', $summary['args']);
            $this->assertStringContainsString('"parameters":', $summary['args']);
        }
    }
}
