<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Jobs\RequestDefinitionJob;
use App\Mcp\RequestDefinitionTool;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Test the request_definition MCP tool functionality
 */
class RequestDefinitionToolTest extends TestCase
{
    private RequestDefinitionTool $tool;

    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake the queue to prevent jobs from actually being executed
        Queue::fake();

        $this->tool = app(RequestDefinitionTool::class);

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
    }

    /**
     * Helper to call the tool and parse the response
     */
    private function callTool(int $queryId, string $subject): array
    {
        $request = new Request([
            'query_id' => $queryId,
            'subject' => $subject,
        ]);

        $response = $this->tool->handle($request);
        $content = $response->content()
            ->toArray();

        return json_decode($content['text'], true);
    }

    /**
     * Test successful definition request
     */
    public function test_request_definition_success(): void
    {
        $result = $this->callTool($this->query->id, 'paused member');

        $this->assertTrue($result['ok']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals($this->query->id, $result['query_id']);
        $this->assertEquals('paused member', $result['subject']);
        $this->assertEquals('Definition requested; awaiting user input.', $result['message']);

        // Assert that the job was dispatched
        Queue::assertPushed(RequestDefinitionJob::class);
    }

    /**
     * Test error handling for invalid query ID
     */
    public function test_invalid_query_id_error(): void
    {
        $result = $this->callTool(99999, 'paused member');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Query not found', $result['error']);

        // Assert that no job was dispatched
        Queue::assertNotPushed(RequestDefinitionJob::class);
    }

    /**
     * Test error handling for invalid subject
     */
    public function test_invalid_subject_error(): void
    {
        $result = $this->callTool($this->query->id, '');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid subject provided', $result['error']);

        // Assert that no job was dispatched
        Queue::assertNotPushed(RequestDefinitionJob::class);
    }

    /**
     * Test error handling for empty subject
     */
    public function test_empty_subject_error(): void
    {
        $result = $this->callTool($this->query->id, '   ');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid subject provided', $result['error']);

        // Assert that no job was dispatched
        Queue::assertNotPushed(RequestDefinitionJob::class);
    }

    /**
     * Test subject normalization (trimming)
     */
    public function test_subject_normalization(): void
    {
        $result = $this->callTool($this->query->id, '  paused member  ');

        $this->assertTrue($result['ok']);
        $this->assertEquals('paused member', $result['subject']);

        // Assert that the job was dispatched with normalized subject
        Queue::assertPushed(RequestDefinitionJob::class);
    }
}
