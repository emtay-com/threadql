<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * Test Slack interactive feedback functionality
 */
class InteractiveFeedbackTest extends TestCase
{
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a tenant for all tests
        $this->tenant = Tenant::factory()->create();

        // Disable signature verification middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);

        // Mock the SlackMessenger to avoid real API calls
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('updateMessage')
                ->andReturn(true);
        });
    }

    /**
     * Test that clicking the "Yes" button updates query score to 1
     */
    public function test_yes_button_updates_score_to_one(): void
    {
        // Create a test query
        $query = Query::factory()->create([
            'score' => 0,
        ]);

        // Mock Slack payload for "yes" button
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "yes_button_{$query->id}",
                    'value' => 'yes',
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();

        // Assert query score was updated
        $query->refresh();
        $this->assertEquals(1, $query->score);
    }

    /**
     * Test that clicking the "No" button updates query score to -1
     */
    public function test_no_button_updates_score_to_minus_one(): void
    {
        // Create a test query
        $query = Query::factory()->create([
            'score' => 0,
        ]);

        // Mock Slack payload for "no" button
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "no_button_{$query->id}",
                    'value' => 'no',
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();

        // Assert query score was updated
        $query->refresh();
        $this->assertEquals(-1, $query->score);
    }

    /**
     * Test that invalid action_id is ignored
     */
    public function test_invalid_action_id_is_ignored(): void
    {
        // Create a test query
        $query = Query::factory()->create([
            'score' => 0,
        ]);

        // Mock Slack payload with invalid action_id
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "maybe_button_{$query->id}",
                    'value' => 'maybe',
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();

        // Assert query score was NOT updated
        $query->refresh();
        $this->assertEquals(0, $query->score);
    }

    /**
     * Test that non-existent query is handled gracefully with 204
     */
    public function test_non_existent_query_returns_204(): void
    {
        // Mock Slack payload with non-existent query ID
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'yes_button_99999',
                    'value' => 'yes',
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 (gracefully handled)
        $response->assertStatus(204);
    }

    /**
     * Test that non-block_actions type is ignored
     */
    public function test_non_block_actions_type_is_ignored(): void
    {
        // Create a test query
        $query = Query::factory()->create([
            'score' => 0,
        ]);

        // Mock Slack payload with different type
        $payload = [
            'type' => 'unknown_type',
            'actions' => [
                [
                    'action_id' => "yes_button_{$query->id}",
                    'value' => 'yes',
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();

        // Assert query score was NOT updated
        $query->refresh();
        $this->assertEquals(0, $query->score);
    }

    /**
     * Test that missing payload parameter is handled gracefully
     */
    public function test_missing_payload_parameter_handled_gracefully(): void
    {
        // Make request without payload parameter
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", []);

        // Should return 204 (graceful handling)
        $response->assertNoContent();
    }

    /**
     * Test that malformed JSON payload is handled gracefully
     */
    public function test_malformed_json_payload_is_handled_gracefully(): void
    {
        // Make request with malformed JSON
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => 'invalid json {',
        ]);

        // Should return 204 (graceful handling)
        $response->assertNoContent();
    }
}
