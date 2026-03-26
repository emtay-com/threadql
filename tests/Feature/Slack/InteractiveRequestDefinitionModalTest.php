<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Tests\TestCase;

/**
 * Test the Slack interactive request definition modal functionality
 */
class InteractiveRequestDefinitionModalTest extends TestCase
{
    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable signature verification middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);

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

        // Mock the SlackMessenger to avoid real API calls
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('openModal')
                ->andReturn(true);
        });
    }

    /**
     * Test that clicking the request definition button opens modal with correct data
     */
    public function test_request_definition_button_opens_modal(): void
    {
        $subject = 'paused member';

        // Mock Slack payload for request definition button
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "request_definition_{$this->query->id}",
                    'value' => $subject,
                ],
            ],
            'trigger_id' => 'test_trigger_123',
            'message' => [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => 'Test message',
                        ],
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'action_id' => "request_definition_{$this->query->id}",
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => 'Add Definition',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();
    }

    /**
     * Test that modal is opened with correct view structure
     */
    public function test_modal_opened_with_correct_view(): void
    {
        $subject = 'paused member';

        // Mock Slack payload for request definition button
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "request_definition_{$this->query->id}",
                    'value' => $subject,
                ],
            ],
            'trigger_id' => 'test_trigger_123',
            'message' => [
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => 'Test message',
                        ],
                    ],
                    [
                        'type' => 'actions',
                        'elements' => [
                            [
                                'type' => 'button',
                                'action_id' => "request_definition_{$this->query->id}",
                                'text' => [
                                    'type' => 'plain_text',
                                    'text' => 'Add Definition',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // Mock the SlackMessenger to capture the view parameter
        $this->mock(SlackMessenger::class, function ($mock) use ($subject) {
            $mock->shouldReceive('openModal')
                ->with('test_trigger_123', \Mockery::on(function ($view) use ($subject) {
                    return $view['type'] === 'modal'
                        && $view['callback_id'] === "request_definition_modal_{$this->query->id}"
                        && $view['title']['text'] === 'Add Definition'
                        && $view['blocks'][0]['element']['initial_value'] === $subject;
                }))
                ->andReturn(true);
        });

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();
    }

    /**
     * Test that non-existent query returns 204 gracefully
     */
    public function test_non_existent_query_returns_204(): void
    {
        $subject = 'paused member';

        // Mock Slack payload with non-existent query ID
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => 'request_definition_99999',
                    'value' => $subject,
                ],
            ],
            'trigger_id' => 'test_trigger_123',
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();
    }

    /**
     * Test that invalid action_id format is ignored
     */
    public function test_invalid_action_id_format_ignored(): void
    {
        $subject = 'paused member';

        // Mock Slack payload with invalid action_id format
        $payload = [
            'type' => 'block_actions',
            'actions' => [
                [
                    'action_id' => "invalid_format_{$this->query->id}",
                    'value' => $subject,
                ],
            ],
            'trigger_id' => 'test_trigger_123',
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is 204 No Content
        $response->assertNoContent();
    }
}
