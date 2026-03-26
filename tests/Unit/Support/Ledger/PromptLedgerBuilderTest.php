<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ledger;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Support\Ledger\PromptLedgerBuilder;
use Tests\TestCase;

class PromptLedgerBuilderTest extends TestCase
{
    /**
     * Test building ledger for query with multiple tool calls.
     */
    public function test_build_ledger_for_query_with_multiple_tool_calls(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Create tool calls in order
        $toolCall1 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"tables":["orders","order_items"]}',
            'response_payload' => '{"found":["orders","order_items"],"missing":[]}',
        ]);

        sleep(1); // Ensure ordering

        $toolCall2 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'request_definition',
            'request_payload' => '{"subject":"paused member"}',
            'response_payload' => '{"ok":true,"status":"pending","subject":"paused member"}',
        ]);

        sleep(1); // Ensure ordering

        $toolCall3 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"query":"SELECT COUNT(*) FROM orders"}',
            'response_payload' => '{"result_kind":"aggregate","aggregate":{"label":"count","value":150}}',
        ]);

        // Build the ledger
        $ledger = PromptLedgerBuilder::buildForQuery($query->id);

        // Verify the ledger format
        $this->assertStringStartsWith('Steps so far:', $ledger);
        $this->assertTrue(str_contains($ledger, '1) Tool: fetch_table_ddls'));
        $this->assertTrue(str_contains($ledger, 'args: {"tables":["orders","order_items"]}'));
        $this->assertTrue(str_contains($ledger, 'result: loaded DDLs: orders, order_items'));

        $this->assertTrue(str_contains($ledger, '2) Tool: request_definition'));
        $this->assertTrue(str_contains($ledger, 'args: {"subject":"paused member"}'));
        $this->assertTrue(str_contains($ledger, 'result: pending — user will provide definition'));

        $this->assertTrue(str_contains($ledger, '3) Tool: run_sql_query'));
        $this->assertTrue(str_contains($ledger, 'args:'));
        $this->assertTrue(str_contains($ledger, 'result: aggregate=count: 150'));
    }

    /**
     * Test building ledger respects max_steps configuration.
     */
    public function test_build_ledger_respects_max_steps_config(): void
    {
        // Set max steps to 4 for this test
        GeneralSetting::create([
            'setting' => SettingEnum::LLM_RESUME_MAX_STEPS,
            'value' => '4',
        ]);

        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Create more tool calls than the max_steps limit (4)
        for ($i = 0; $i < 6; $i++) {
            ToolCall::factory()->create([
                'query_id' => $query->id,
                'tenant_id' => $tenant->id,
                'tool' => 'run_sql_query',
                'request_payload' => '{"query":"SELECT '.$i.'"}',
                'response_payload' => '{"result_kind":"rows","row_count":1,"truncated":false}',
            ]);
            sleep(1); // Ensure ordering
        }

        // Build the ledger
        $ledger = PromptLedgerBuilder::buildForQuery($query->id);

        // Should only contain the first 4 steps (max_steps = 4)
        $this->assertTrue(str_contains($ledger, '1) Tool: run_sql_query'));
        $this->assertTrue(str_contains($ledger, '2) Tool: run_sql_query'));
        $this->assertTrue(str_contains($ledger, '3) Tool: run_sql_query'));
        $this->assertTrue(str_contains($ledger, '4) Tool: run_sql_query'));

        // Should NOT contain the 5th and 6th steps
        $this->assertFalse(str_contains($ledger, '5) Tool: run_sql_query'));
        $this->assertFalse(str_contains($ledger, '6) Tool: run_sql_query'));
    }

    /**
     * Test building ledger for query with no tool calls.
     */
    public function test_build_ledger_for_query_with_no_tool_calls(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        // Build the ledger
        $ledger = PromptLedgerBuilder::buildForQuery($query->id);

        // Should return empty string
        $this->assertEquals('', $ledger);
    }

    /**
     * Test ledger format matches expected structure.
     */
    public function test_ledger_format_matches_expected_structure(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"query":"SELECT 1"}',
            'response_payload' => '{"result_kind":"rows","row_count":1,"truncated":false}',
        ]);

        // Build the ledger
        $ledger = PromptLedgerBuilder::buildForQuery($query->id);

        // Verify the exact format
        $expected = "Steps so far:\n".
                   "1) Tool: run_sql_query\n".
                   "   args: {\"query\":\"SELECT 1\"}\n".
                   '   result: rows=1, truncated=false';

        $this->assertEquals($expected, $ledger);
    }
}
