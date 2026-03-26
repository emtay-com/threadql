<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Query;

use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Services\Query\ToolCallPersister;
use Tests\TestCase;

class ToolCallPersisterTest extends TestCase
{
    private ToolCallPersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persister = new ToolCallPersister();
    }

    public function test_persists_tool_call_ids_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $toolCall1 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => null,
        ]);

        sleep(1);

        $toolCall2 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'extract_table_ddl',
            'function_call_id' => null,
        ]);

        $prismToolCalls = [
            $this->createMockPrismToolCall('fc_abc123', 'run_sql_query', 'result_abc'),
            $this->createMockPrismToolCall('fc_def456', 'extract_table_ddl', 'result_def'),
        ];

        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $toolCall1->refresh();
        $toolCall2->refresh();

        $this->assertEquals('fc_abc123', $toolCall1->function_call_id);
        $this->assertEquals('result_abc', $toolCall1->result_id);
        $this->assertEquals('fc_def456', $toolCall2->function_call_id);
        $this->assertEquals('result_def', $toolCall2->result_id);
    }

    public function test_does_not_overwrite_existing_function_call_id(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => 'existing_fc_123',
        ]);

        $prismToolCalls = [$this->createMockPrismToolCall('fc_new456', 'run_sql_query', 'result_new')];

        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $toolCall->refresh();
        $this->assertEquals('existing_fc_123', $toolCall->function_call_id);
    }

    public function test_handles_empty_prism_tool_calls(): void
    {
        $this->persister->persistToolCallIds([], 123);

        // Should not throw an exception
        $this->assertTrue(true);
    }

    public function test_handles_no_db_tool_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $prismToolCalls = [$this->createMockPrismToolCall('fc_123', 'run_sql_query', 'result_123')];

        // Should not throw an exception when no DB tool calls exist
        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $this->assertTrue(true);
    }

    public function test_handles_more_prism_calls_than_db_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => null,
        ]);

        $prismToolCalls = [
            $this->createMockPrismToolCall('fc_1', 'run_sql_query', 'result_1'),
            $this->createMockPrismToolCall('fc_2', 'extract_table_ddl', 'result_2'),
        ];

        // Should only update the first one and not crash
        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $toolCall->refresh();
        $this->assertEquals('fc_1', $toolCall->function_call_id);
    }

    public function test_matches_by_name_regardless_of_order(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // DB records created in one order
        $toolCall1 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'extract_table_ddl',
            'function_call_id' => null,
        ]);

        sleep(1);

        $toolCall2 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => null,
        ]);

        // Prism tool calls in reverse order
        $prismToolCalls = [
            $this->createMockPrismToolCall('fc_sql', 'run_sql_query', 'result_sql'),
            $this->createMockPrismToolCall('fc_ddl', 'extract_table_ddl', 'result_ddl'),
        ];

        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $toolCall1->refresh();
        $toolCall2->refresh();

        // Should match by name, not index
        $this->assertEquals('fc_ddl', $toolCall1->function_call_id);
        $this->assertEquals('result_ddl', $toolCall1->result_id);
        $this->assertEquals('fc_sql', $toolCall2->function_call_id);
        $this->assertEquals('result_sql', $toolCall2->result_id);
    }

    public function test_matches_same_tool_called_twice_with_different_arguments(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $toolCall1 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => null,
            'request_payload' => [
                'sql' => 'SELECT * FROM customers',
            ],
        ]);

        sleep(1);

        $toolCall2 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => null,
            'request_payload' => [
                'sql' => 'SELECT * FROM orders',
            ],
        ]);

        $prismToolCalls = [
            $this->createMockPrismToolCallWithArgs('fc_1', 'run_sql_query', 'result_1', [
                'sql' => 'SELECT * FROM customers',
            ]),
            $this->createMockPrismToolCallWithArgs('fc_2', 'run_sql_query', 'result_2', [
                'sql' => 'SELECT * FROM orders',
            ]),
        ];

        $this->persister->persistToolCallIds($prismToolCalls, $query->id);

        $toolCall1->refresh();
        $toolCall2->refresh();

        // First prism call matches first DB record (by payload similarity)
        $this->assertEquals('fc_1', $toolCall1->function_call_id);
        $this->assertEquals('fc_2', $toolCall2->function_call_id);
    }

    public function test_create_missing_tool_call_records_creates_when_no_db_match(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $prismToolCalls = [$this->createMockPrismToolCall('fc_orphan', 'run_sql_query', 'result_orphan')];

        $this->persister->createMissingToolCallRecords($prismToolCalls, $query->id, $tenant->id);

        $created = ToolCall::where('query_id', $query->id)->first();
        $this->assertNotNull($created);
        $this->assertEquals('run_sql_query', $created->tool);
        $this->assertEquals('fc_orphan', $created->function_call_id);
        $this->assertEquals('result_orphan', $created->result_id);
        $this->assertFalse($created->is_completed);
        $this->assertEquals([
            'error' => 'Tool call not captured by MCP transport layer',
        ], $created->response_payload);
    }

    public function test_create_missing_tool_call_records_skips_when_db_records_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'function_call_id' => 'fc_existing',
        ]);

        $prismToolCalls = [$this->createMockPrismToolCall('fc_existing', 'run_sql_query', 'result_1')];

        $this->persister->createMissingToolCallRecords($prismToolCalls, $query->id, $tenant->id);

        // Should not create a duplicate
        $count = ToolCall::where('query_id', $query->id)->count();
        $this->assertEquals(1, $count);
    }

    public function test_create_missing_tool_call_records_handles_empty_calls(): void
    {
        $this->persister->createMissingToolCallRecords([], 123, 1);
        $this->assertTrue(true);
    }

    private function createMockPrismToolCall(string $id, string $name, string $resultId): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'result_id' => $resultId,
        ];
    }

    private function createMockPrismToolCallWithArgs(
        string $id,
        string $name,
        string $resultId,
        array $arguments
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'result_id' => $resultId,
            'arguments' => $arguments,
        ];
    }
}
