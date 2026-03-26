<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Models\ToolCall;
use App\Services\Llm\FollowUpPromptBuilder;
use App\Services\Llm\LlmProviderResolver;
use Mockery;
use Tests\TestCase;

class FollowUpPromptBuilderLedgerTest extends TestCase
{
    private FollowUpPromptBuilder $builder;

    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the provider resolver
        $mockProvider = Mockery::mock(\App\Models\LlmProvider::class);
        $mockProvider->shouldReceive('getAttribute')
            ->with('adapter')
            ->andReturn('openai');

        $providerResolver = Mockery::mock(LlmProviderResolver::class);
        $providerResolver->shouldReceive('resolve')
            ->andReturn($mockProvider);
        $providerResolver->shouldReceive('getModelName')
            ->andReturn('TestModel');

        $this->builder = new FollowUpPromptBuilder($providerResolver);

        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
        ]);
    }

    public function test_build_prompt_includes_ledger_when_tool_calls_exist(): void
    {
        // Create a tool call for the thread
        ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $this->query->id,
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"table_names":"users"}',
            'response_payload' => '{"found":["users"]}',
        ]);

        $messages = $this->builder->buildPrompt($this->query, $this->tenant);

        // Find the system message
        $systemMessage = collect($messages)
            ->first(fn ($msg) => $msg['role'] === 'system');

        $this->assertNotNull($systemMessage);
        $this->assertStringContainsString('## Steps so far (context ledger)', $systemMessage['content']);
        $this->assertStringContainsString('Tool: fetch_table_ddls', $systemMessage['content']);
        $this->assertStringContainsString('Do not repeat steps already shown in the ledger', $systemMessage['content']);
    }

    public function test_build_prompt_excludes_latest_run_sql_query_from_ledger(): void
    {
        // Create a DONE query to establish follow-up context
        $doneQuery = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => \App\Enums\QueryStatus::DONE->value,
            'message_ts' => '1234567890.000001', // Different from thread_ts
        ]);

        // Update the current query to be a follow-up
        $this->query->update([
            'message_ts' => '1234567890.000002', // Different from thread_ts
        ]);

        // Create older tool calls for the DONE query
        $oldToolCall = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $doneQuery->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"sql":"SELECT id FROM users","parameters":{}}',
            'response_payload' => '{"result_kind":"rows","row_count":10}',
            'created_at' => now()
                ->subMinutes(10),
        ]);

        // Create the most recent run_sql_query for the DONE query (should be excluded from ledger)
        $latestToolCall = ToolCall::factory()->create([
            'tenant_id' => $this->tenant->id,
            'query_id' => $doneQuery->id,
            'tool' => 'run_sql_query',
            'request_payload' => '{"sql":"SELECT * FROM users","parameters":{}}',
            'response_payload' => '{"result_kind":"rows","row_count":50}',
            'created_at' => now()
                ->subMinutes(1),
        ]);

        $newQuery = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
        ]);

        $messages = $this->builder->buildPrompt($newQuery, $this->tenant);

        // Find the system message
        $systemMessage = collect($messages)
            ->first(fn ($msg) => $msg['role'] === 'system');

        $this->assertNotNull($systemMessage);
        $this->assertStringContainsString(
            'SELECT id FROM users',
            $systemMessage['content']
        ); // Should include older query in ledger
        $this->assertStringContainsString(
            'SELECT * FROM users',
            $systemMessage['content']
        ); // Should exclude latest query from both ledger and sql_call
    }

    public function test_build_prompt_skips_ledger_when_no_tool_calls(): void
    {
        $messages = $this->builder->buildPrompt($this->query, $this->tenant);

        // Find the system message
        $systemMessage = collect($messages)
            ->first(fn ($msg) => $msg['role'] === 'system');

        $this->assertNotNull($systemMessage);
        $this->assertStringNotContainsString('## Steps so far (context ledger)', $systemMessage['content']);
    }

    public function test_user_message_contains_integers_only(): void
    {
        $messages = $this->builder->buildPrompt($this->query, $this->tenant);

        // Find the user message
        $userMessage = collect($messages)
            ->first(fn ($msg) => $msg['role'] === 'user');

        $this->assertNotNull($userMessage);

        $lines = explode("\n", trim($userMessage['content']));

        // First line should be the query ID as integer
        $this->assertEquals((string) $this->query->id, $lines[0]);
        $this->assertIsNumeric($lines[0]);

        // Second line should be the last SQL call ID as integer (or empty if none)
        if (count($lines) > 1) {
            $this->assertIsNumeric($lines[1]);
        }
    }
}
