<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Http\Middleware\ValidateSlackSignature;
use App\Jobs\PaginateQueryJob;
use App\Models\Query;
use App\Models\Tenant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Test suite for interactive pagination button handling
 */
class InteractivePaginationHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable signature verification middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);
    }

    /**
     * Test that pagination button click returns 204 and dispatches job
     */
    public function test_pagination_button_returns_204_and_dispatches_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'done',
            'result_meta_json' => [
                'total_count' => 1000,
                'limit_applied' => 100,
                'parameters' => [
                    'offset' => 0,
                ],
            ],
        ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "query_pagination_next_{$query->id}",
                    'value' => '100',
                ],
            ],
        ];

        $response = $this->postJson("/api/{$tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(204);

        Bus::assertDispatched(PaginateQueryJob::class, function ($job) use ($query) {
            return $job->queryId === $query->id
                && $job->requestedOffset === 100
                && $job->currentOffset === 0;
        });
    }

    /**
     * Test that pagination button for non-existent query still returns 204
     */
    public function test_pagination_button_for_missing_query_returns_204(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'query_pagination_next_99999',
                    'value' => json_encode([
                        'offset' => 0,
                    ]),
                ],
            ],
        ];

        $response = $this->postJson("/api/{$tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(400);

        // Assert that no PaginateQueryJob was dispatched
        Bus::assertNotDispatched(PaginateQueryJob::class);
    }

    /**
     * Test that unknown action_id is handled gracefully
     */
    public function test_unknown_action_id_returns_204(): void
    {
        $tenant = Tenant::factory()->create();

        Log::shouldReceive('warning')
            ->once()
            ->with('Unknown action_id format', [
                'action_id' => 'unknown_action_123',
            ]);

        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'unknown_action_123',
                    'value' => 'some_value',
                ],
            ],
        ];

        $response = $this->postJson("/api/{$tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        $response->assertStatus(204);
    }
}
