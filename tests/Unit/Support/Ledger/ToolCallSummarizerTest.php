<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Ledger;

use App\Models\ToolCall;
use App\Support\Ledger\ToolCallSummarizer;
use Tests\TestCase;

class ToolCallSummarizerTest extends TestCase
{
    /**
     * Test summarizing fetch_table_ddls with found tables.
     */
    public function test_summarize_fetch_table_ddls_with_found_tables(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"tables":["orders","order_items"]}',
            'response_payload' => '{"found":["orders","order_items"],"missing":[]}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertEquals('{"tables":["orders","order_items"]}', $summary['args']);
        $this->assertEquals('loaded DDLs: orders, order_items', $summary['result']);
    }

    /**
     * Test summarizing fetch_table_ddls with missing tables.
     */
    public function test_summarize_fetch_table_ddls_with_missing_tables(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"tables":["orders","users"]}',
            'response_payload' => '{"found":["orders"],"missing":["users"]}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertEquals('{"tables":["orders","users"]}', $summary['args']);
        $this->assertEquals('loaded DDLs: orders; missing: users', $summary['result']);
    }

    /**
     * Test summarizing run_sql_query with aggregate results.
     */
    public function test_summarize_run_sql_query_aggregate(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'run_sql_query',
            'request_payload' => '{"query":"SELECT COUNT(*) as total FROM users"}',
            'response_payload' => json_encode([
                'result_kind' => 'aggregate',
                'aggregate' => [
                    'label' => 'total',
                    'value' => 42,
                ],
            ]),
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'SELECT COUNT(*) as total FROM users'));
        $this->assertEquals('aggregate=total: 42', $summary['result']);
    }

    /**
     * Test summarizing run_sql_query with rows results.
     */
    public function test_summarize_run_sql_query_rows(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'run_sql_query',
            'request_payload' => '{"query":"SELECT * FROM users LIMIT 10"}',
            'response_payload' => json_encode([
                'result_kind' => 'rows',
                'row_count' => 10,
                'truncated' => false,
            ]),
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'SELECT * FROM users LIMIT 10'));
        $this->assertEquals('rows=10, truncated=false', $summary['result']);
    }

    /**
     * Test summarizing run_sql_query with truncated rows.
     */
    public function test_summarize_run_sql_query_rows_truncated(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'run_sql_query',
            'request_payload' => '{"query":"SELECT * FROM users"}',
            'response_payload' => json_encode([
                'result_kind' => 'rows',
                'row_count' => 1000,
                'truncated' => true,
            ]),
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'SELECT * FROM users'));
        $this->assertEquals('rows=1000, truncated=true', $summary['result']);
    }

    /**
     * Test summarizing request_definition.
     */
    public function test_summarize_request_definition(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'request_definition',
            'request_payload' => '{"subject":"paused member"}',
            'response_payload' => '{"ok":true,"status":"pending","subject":"paused member"}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertEquals('{"subject":"paused member"}', $summary['args']);
        $this->assertEquals('pending — user will provide definition', $summary['result']);
    }

    /**
     * Test summarizing parse_definition success.
     */
    public function test_summarize_parse_definition_success(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'parse_definition',
            'request_payload' => '{"subject":"active user","definition":"A user with recent activity"}',
            'response_payload' => '{"success":true}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'active user'));
        $this->assertEquals('parsed successfully', $summary['result']);
    }

    /**
     * Test summarizing parse_definition failure.
     */
    public function test_summarize_parse_definition_failure(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'parse_definition',
            'request_payload' => '{"subject":"confusing term","definition":""}',
            'response_payload' => '{"success":false,"error":"Invalid definition"}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'confusing term'));
        $this->assertEquals('parsing failed', $summary['result']);
    }

    /**
     * Test summarizing create_definition success.
     */
    public function test_summarize_create_definition_success(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'create_definition',
            'request_payload' => '{"subject":"new term","definition":"A new business term"}',
            'response_payload' => '{"created":true}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'new term'));
        $this->assertEquals('definition created', $summary['result']);
    }

    /**
     * Test summarizing create_definition failure.
     */
    public function test_summarize_create_definition_failure(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'create_definition',
            'request_payload' => '{"subject":"invalid term","definition":""}',
            'response_payload' => '{"created":false,"error":"Invalid definition"}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertTrue(str_contains($summary['args'], 'invalid term'));
        $this->assertEquals('definition creation failed', $summary['result']);
    }

    /**
     * Test summarizing unknown tool.
     */
    public function test_summarize_unknown_tool(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'unknown_tool',
            'request_payload' => '{"param":"value"}',
            'response_payload' => '{"result":"some output"}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertEquals('{"param":"value"}', $summary['args']);
        $this->assertEquals('done', $summary['result']);
    }

    /**
     * Test argument truncation for long arguments.
     */
    public function test_argument_truncation_for_long_args(): void
    {
        $longArgs = str_repeat('a', 300); // Longer than max_args_len (200)
        $toolCall = new ToolCall([
            'tool' => 'run_sql_query',
            'request_payload' => "{\"query\":\"{$longArgs}\"}",
            'response_payload' => '{"result_kind":"rows","row_count":1,"truncated":false}',
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        // Should be truncated and show keys only
        $this->assertStringStartsWith('{"query":', $summary['args']);
        $this->assertTrue(str_contains($summary['args'], '...'));
        $this->assertEquals('rows=1, truncated=false', $summary['result']);
    }

    /**
     * Test handling of empty payloads.
     */
    public function test_empty_payloads(): void
    {
        $toolCall = new ToolCall([
            'tool' => 'run_sql_query',
            'request_payload' => null,
            'response_payload' => null,
        ]);

        $summary = ToolCallSummarizer::summarize($toolCall);

        $this->assertEquals('{}', $summary['args']);
        $this->assertEquals('no result', $summary['result']);
    }
}
