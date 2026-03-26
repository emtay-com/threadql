<?php

declare(strict_types=1);

namespace Tests\Unit\Prompt;

use App\Enums\MessageRole;
use App\Enums\QueryStatus;
use App\Models\Definition;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Services\Llm\PromptBuilder;
use Tests\TestCase;

class PromptBuilderResumeLedgerTest extends TestCase
{
    private PromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the provider resolver globally for all tests
        $provider = new \App\Models\LlmProvider([
            'name' => 'OpenAI GPT-4',
            'adapter' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'model_name' => 'gpt-4',
            'api_key' => 'test-key',
        ]);

        $this->mock(\App\Services\Llm\LlmProviderResolver::class, function ($mock) use ($provider) {
            $mock->shouldReceive('resolve')
                ->andReturn($provider);
            $mock->shouldReceive('getModelName')
                ->andReturn('gpt-4');
        });

        $this->promptBuilder = app(PromptBuilder::class);
    }

    /**
     * Test that resume prompt includes Context Ledger with tool call summaries.
     */
    public function test_resume_prompt_includes_context_ledger(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => QueryStatus::INPUT_REQUESTED->value, // This triggers resumption
            'raw_text' => 'Show me all orders',
        ]);

        // Create tool calls for the ledger
        $toolCall1 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'fetch_table_ddls',
            'request_payload' => '{"tables":["orders"]}',
            'response_payload' => '{"found":["orders"],"missing":[]}',
        ]);

        sleep(1);

        $toolCall2 = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tenant_id' => $tenant->id,
            'tool' => 'request_definition',
            'request_payload' => '{"subject":"active order"}',
            'response_payload' => '{"ok":true,"status":"pending","subject":"active order"}',
        ]);

        // Create a definition for the thread
        Definition::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $query->thread_id,
            'subject' => 'active order',
            'definition' => 'An order that has been placed and is being processed',
        ]);

        // Build the resume prompt
        $messages = $this->promptBuilder->buildPrompt($query, $tenant);

        // Verify the message structure
        $this->assertCount(4, $messages);

        // 1. System message
        $this->assertEquals(MessageRole::SYSTEM->value, $messages[0]['role']);
        $this->assertIsString($messages[0]['content']);

        $this->assertEquals(MessageRole::USER->value, $messages[1]['role']);

        // 3. Context Ledger (assistant message)
        $this->assertEquals(MessageRole::ASSISTANT->value, $messages[2]['role']);
        $ledger = $messages[2]['content'];
        $this->assertStringStartsWith('Steps so far:', $ledger);
        $this->assertTrue(str_contains($ledger, '1) Tool: fetch_table_ddls'));
        $this->assertTrue(str_contains($ledger, 'args: {"tables":["orders"]}'));
        $this->assertTrue(str_contains($ledger, 'result: loaded DDLs: orders'));
        $this->assertTrue(str_contains($ledger, '2) Tool: request_definition'));
        $this->assertTrue(str_contains($ledger, 'args: {"subject":"active order"}'));
        $this->assertTrue(str_contains($ledger, 'result: pending — user will provide definition'));

        // 4. Final user message with definitions
        $this->assertEquals(MessageRole::USER->value, $messages[3]['role']);
        $finalMessage = $messages[3]['content'];
        $this->assertStringStartsWith('Here is the definition, please prepare the query now.', $finalMessage);
        $this->assertTrue(
            str_contains($finalMessage, 'active order => An order that has been placed and is being processed')
        );
    }

    /**
     * Test that resume prompt for RECEIVED status doesn't include ledger.
     */
    public function test_initial_prompt_does_not_include_ledger(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => QueryStatus::RECEIVED->value, // Initial status
            'raw_text' => 'Show me all orders',
        ]);

        // Build the initial prompt
        $messages = $this->promptBuilder->buildPrompt($query, $tenant);

        // Should only have system + user messages, no ledger
        $this->assertCount(2, $messages);
        $this->assertEquals(MessageRole::SYSTEM->value, $messages[0]['role']);
        $this->assertEquals(MessageRole::USER->value, $messages[1]['role']);

        // No assistant message with ledger
        foreach ($messages as $message) {
            $this->assertNotEquals(MessageRole::ASSISTANT->value, $message['role']);
        }
    }

    /**
     * Test that ledger is empty when query has no tool calls.
     */
    public function test_resume_prompt_with_no_tool_calls(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => QueryStatus::INPUT_REQUESTED->value,
            'raw_text' => 'Show me all orders',
        ]);

        // Build the resume prompt
        $messages = $this->promptBuilder->buildPrompt($query, $tenant);

        // Should have system + user + final user (no ledger)
        $this->assertCount(2, $messages);
        $this->assertEquals(MessageRole::SYSTEM->value, $messages[0]['role']);
        $this->assertEquals(MessageRole::USER->value, $messages[1]['role']);

        // No assistant message
        foreach ($messages as $message) {
            $this->assertNotEquals(MessageRole::ASSISTANT->value, $message['role']);
        }
    }
}
