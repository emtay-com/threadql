<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Query;

use App\Enums\QueryStatus;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Services\Query\QueryStatusCalculator;
use Tests\TestCase;

class QueryStatusCalculatorTest extends TestCase
{
    private QueryStatusCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new QueryStatusCalculator();
    }

    public function test_returns_done_when_no_tool_calls_exist(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $status = $this->calculator->calculateFinalStatus($query->id);

        $this->assertEquals(QueryStatus::DONE, $status);
    }

    public function test_returns_done_when_last_tool_is_run_sql_query(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
        ]);

        $status = $this->calculator->calculateFinalStatus($query->id);

        $this->assertEquals(QueryStatus::DONE, $status);
    }

    public function test_returns_input_requested_when_last_tool_is_not_run_sql_query(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'request_definition',
        ]);

        $status = $this->calculator->calculateFinalStatus($query->id);

        $this->assertEquals(QueryStatus::INPUT_REQUESTED, $status);
    }

    public function test_returns_done_when_any_tool_call_is_completing(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Completing tool call (older)
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'created_at' => now()
                ->subMinutes(5),
        ]);

        // Non-completing tool call added after (e.g. ghost "not captured" record)
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'request_definition',
            'created_at' => now(),
        ]);

        $this->assertEquals(QueryStatus::DONE, $this->calculator->calculateFinalStatus($query->id));
    }

    public function test_returns_done_when_last_tool_is_export_csv(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => true,
        ]);

        $this->assertEquals(QueryStatus::DONE, $this->calculator->calculateFinalStatus($query->id));
    }

    public function test_returns_done_when_last_tool_is_run_query_for_csv_export(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_query_for_csv_export',
            'is_completed' => true,
        ]);

        $this->assertEquals(QueryStatus::DONE, $this->calculator->calculateFinalStatus($query->id));
    }

    public function test_csv_already_delivered_true_for_sync_csv_export(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => true,
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'csv_export',
                'status' => 'pending',
                'row_count' => 100,
            ]),
        ]);

        $this->assertTrue($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_true_for_async_csv_export(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_query_for_csv_export',
            'is_completed' => false,
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'csv_export_async',
                'status' => 'processing',
                'row_count' => 50000,
            ]),
        ]);

        $this->assertTrue($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_false_for_denied_csv_export(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => true,
            'response_payload' => json_encode([
                'ok' => false,
                'result_kind' => 'csv_export_denied',
                'reason' => 'limit_exceeded',
            ]),
        ]);

        $this->assertFalse($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_false_for_failed_csv_export(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => false,
            'response_payload' => json_encode([
                'ok' => false,
                'result_kind' => 'csv_export_failed',
                'reason' => 'unexpected_error',
            ]),
        ]);

        $this->assertFalse($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_false_for_non_csv_tool(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'is_completed' => true,
        ]);

        $this->assertFalse($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_true_even_when_ghost_record_follows(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Successful CSV export (created by MCP tool handler)
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_query_for_csv_export',
            'is_completed' => true,
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'csv_export',
                'row_count' => 100,
            ]),
            'created_at' => now()
                ->subSeconds(5),
        ]);

        // Ghost "not captured" record created by ToolCallPersister
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => false,
            'response_payload' => json_encode([
                'error' => 'Tool call not captured by MCP transport layer',
            ]),
            'created_at' => now(),
        ]);

        $this->assertTrue($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_csv_already_delivered_true_when_both_csv_tools_called(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // LLM called both tools — first one succeeded
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_query_for_csv_export',
            'is_completed' => true,
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'csv_export',
                'row_count' => 200,
            ]),
            'created_at' => now()
                ->subSeconds(3),
        ]);

        // Second CSV tool also called (e.g. LLM confusion)
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => false,
            'response_payload' => json_encode([
                'ok' => false,
                'result_kind' => 'csv_export_failed',
                'reason' => 'unexpected_error',
            ]),
            'created_at' => now(),
        ]);

        $this->assertTrue($this->calculator->wasCsvAlreadyDelivered($query->id));
    }

    public function test_returns_done_when_csv_export_mixed_with_uncaptured_record(): void
    {
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'export_csv',
            'is_completed' => true,
            'created_at' => now()
                ->subSeconds(5),
        ]);

        // Ghost record with unknown tool name
        ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'unknown_tool',
            'is_completed' => false,
            'response_payload' => json_encode([
                'error' => 'Tool call not captured by MCP transport layer',
            ]),
            'created_at' => now(),
        ]);

        $this->assertEquals(QueryStatus::DONE, $this->calculator->calculateFinalStatus($query->id));
    }
}
