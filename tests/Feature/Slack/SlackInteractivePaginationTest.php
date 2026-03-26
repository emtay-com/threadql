<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Http\Middleware\ValidateSlackSignature;
use App\Jobs\PaginateQueryJob;
use App\Models\Query;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class SlackInteractivePaginationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // Disable signature verification middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);
    }

    public function test_pagination_button_dispatches_job_with_correct_parameters(): void
    {
        Bus::fake();

        // Create a query with pagination metadata
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'done',
            'result_meta_json' => [
                'total_count' => 100,
                'limit_applied' => 25,
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => 25,
                ],
            ],
        ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'query_pagination_next_'.$query->id,
                    'value' => '25',
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
            'channel' => [
                'id' => 'C1234567890',
            ],
            'message' => [
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
            ],
            'container' => [
                'message_ts' => '1234567890.123456',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(204);

        // Assert that PaginateQueryJob was dispatched with correct parameters
        Bus::assertDispatched(PaginateQueryJob::class, function ($job) use ($query) {
            return $job->queryId === $query->id
                && $job->requestedOffset === 25
                && $job->currentOffset === 0;
        });
    }

    public function test_pagination_button_validates_query_state(): void
    {
        Bus::fake();

        // Create a query that's not done
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'executing', // Not done
        ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'query_pagination_next_'.$query->id,
                    'value' => '25',
                ],
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Should return 204 (no error, just ignored)
        $response->assertStatus(204);

        // Job should not be dispatched
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_pagination_button_handles_invalid_json_value(): void
    {
        Bus::fake();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'done',
        ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'query_pagination_next_'.$query->id,
                    'value' => 'invalid value',
                ],
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(400);
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    public function test_pagination_button_handles_missing_offset_in_value(): void
    {
        Bus::fake();

        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'done',
        ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'query_pagination_next_'.$query->id,
                    'value' => '',
                ],
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(400);
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }
}
