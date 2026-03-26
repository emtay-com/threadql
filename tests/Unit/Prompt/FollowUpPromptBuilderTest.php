<?php

declare(strict_types=1);

namespace Tests\Unit\Prompt;

use App\Models\LlmProvider;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Services\Llm\FollowUpPromptBuilder;
use Tests\TestCase;

/**
 * Test the FollowUpPromptBuilder functionality
 */
class FollowUpPromptBuilderTest extends TestCase
{
    private FollowUpPromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = app(FollowUpPromptBuilder::class);
    }

    /**
     * Test that buildPrompt returns expected structure
     */
    public function test_build_prompt_returns_expected_structure(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4o',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => '<@U123> Can you export that data?',
        ]);

        // Build the prompt
        $result = $this->builder->buildPrompt($query, $tenant);

        // Verify structure - new format returns messages array directly
        $this->assertIsArray($result);
        $this->assertCount(2, $result); // system + user

        // Check system message
        $this->assertEquals('system', $result[0]['role']);
        $this->assertStringContainsString(
            'You are continuing an existing thread. Use tools to either',
            $result[0]['content']
        );

        // Check user message
        $this->assertEquals('user', $result[1]['role']);
        $this->assertStringContainsString((string) $query->id, $result[1]['content']);
        $this->assertStringContainsString('0', $result[1]['content']); // last_sql_call_id when none available
    }

    /**
     * Test that prompt includes last_sql_call_id when available
     */
    public function test_build_prompt_includes_last_sql_call_id_when_available(): void
    {
        // Create test data with tool call
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4o',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => '<@U123> Show me the results',
        ]);

        // Create a successful run_sql_query tool call
        $toolCall = ToolCall::factory()->create([
            'query_id' => $query->id,
            'tool' => 'run_sql_query',
            'response_payload' => json_encode([
                'ok' => true,
            ]),
        ]);

        // Build the prompt
        $result = $this->builder->buildPrompt($query, $tenant);

        // Verify last_sql_call_id is included
        $this->assertStringContainsString((string) $toolCall->id, $result[1]['content']);
    }

    /**
     * Test that prompt shows null for last_sql_call_id when no tool calls exist
     */
    public function test_build_prompt_shows_null_when_no_tool_calls(): void
    {
        // Create test data without tool calls
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4o',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => '<@U123> Show me the data',
        ]);

        // Build the prompt
        $result = $this->builder->buildPrompt($query, $tenant);

        // Verify last_sql_call_id is 0 when none available
        $this->assertStringContainsString('0', $result[1]['content']);
    }

    /**
     * Test that user content is cleaned of Slack mentions
     */
    public function test_user_content_is_cleaned_of_slack_mentions(): void
    {
        // Create test data with Slack mention
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4o',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => '<@U123456> Can you show me the active users?',
        ]);

        // Build the prompt
        $result = $this->builder->buildPrompt($query, $tenant);

        // Verify mention is replaced with model name
        $this->assertStringContainsString('Can you show me the active users?', $result[1]['content']);
        $this->assertStringNotContainsString('<@U123456>', $result[1]['content']);
    }
}
