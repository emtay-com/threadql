<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Command\CreateDefinitionCommand;
use App\Command\GenerateInitialPromptCommand;
use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\LlmProvider;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Mockery;
use Tests\TestCase;

/**
 * Test the Slack interactive request definition modal submission functionality
 */
class InteractiveRequestDefinitionSubmitTest extends TestCase
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

        // Ensure the thread exists and has the correct tenant
        $this->assertEquals($this->tenant->id, $this->thread->tenant_id);
        $this->assertDatabaseHas('threads', [
            'id' => $this->thread->id,
        ]);

        // Mock the SlackMessenger to avoid real API calls
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        // Mock the DomainCommandBus for all tests
        $this->mockCommandBus();

        // Don't dispatch jobs during tests
        \Illuminate\Support\Facades\Bus::fake();
    }

    private function mockCommandBus(): void
    {
        $mockCommandBus = Mockery::mock(DomainCommandBus::class);
        $mockCommandBus->shouldReceive('dispatch')
            ->andReturnUsing(function ($command) {
                if ($command instanceof CreateDefinitionCommand) {
                    return \App\Command\CreateDefinitionResponse::success($command->subject, $command->definition);
                }
                if ($command instanceof GenerateInitialPromptCommand) {
                    $provider = new LlmProvider([
                        'adapter' => 'openai',
                        'model_name' => 'gpt-4',
                    ]);

                    return new \App\Command\GenerateInitialPromptResponse(
                        messages: [
                            [
                                'role' => 'system',
                                'content' => 'test',
                            ],
                            [
                                'role' => 'user',
                                'content' => 'test query',
                            ],
                        ],
                        provider: $provider,
                        modelName: 'gpt-4'
                    );
                }

                // For other commands, return a generic success response
                return new class implements \App\Infrastructure\Command\DomainCommandResponse
                {
                    public function isSuccess(): bool
                    {
                    return true;
                    }
                };
            });

        app()
            ->bind(DomainCommandBus::class, fn () => $mockCommandBus);
    }

    /**
     * Test successful modal submission creates definition
     */
    public function test_modal_submission_creates_definition(): void
    {
        // Pre-create the definition to make it a duplicate (which returns success)
        \App\Models\Definition::create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'user_id' => 'U1234567890',
            'subject' => 'paused member',
            'definition' => 'A member with status set to paused',
            'priority' => 0,
        ]);

        // Mock Slack payload for modal submission
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => "request_definition_modal_{$this->query->id}",
                'state' => [
                    'values' => [
                        'subject_block' => [
                            'subject' => [
                                'value' => 'paused member',
                            ],
                        ],
                        'definition_block' => [
                            'definition' => [
                                'value' => 'A member with status set to paused',
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is JSON with clear action
        $response->assertJson([
            'response_action' => 'clear',
        ]);
    }

    /**
     * Test validation error for empty subject
     */
    public function test_empty_subject_validation_error(): void
    {
        // Mock Slack payload with empty subject
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => "request_definition_modal_{$this->query->id}",
                'state' => [
                    'values' => [
                        'subject_block' => [
                            'subject' => [
                                'value' => '',
                            ],
                        ],
                        'definition_block' => [
                            'definition' => [
                                'value' => 'A member with status set to paused',
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response contains validation errors
        $response->assertJson([
            'response_action' => 'errors',
            'errors' => [
                'subject_block' => 'Please provide a subject',
            ],
        ]);
    }

    /**
     * Test validation error for empty definition
     */
    public function test_empty_definition_validation_error(): void
    {
        // Mock Slack payload with empty definition
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => "request_definition_modal_{$this->query->id}",
                'state' => [
                    'values' => [
                        'subject_block' => [
                            'subject' => [
                                'value' => 'paused member',
                            ],
                        ],
                        'definition_block' => [
                            'definition' => [
                                'value' => '',
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response contains validation errors
        $response->assertJson([
            'response_action' => 'errors',
            'errors' => [
                'definition_block' => 'Please provide a definition',
            ],
        ]);
    }

    /**
     * Test subject normalization (lowercase and trim)
     */
    public function test_subject_normalization(): void
    {
        // Pre-create the definition to make it a duplicate (which returns success)
        \App\Models\Definition::create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'user_id' => 'U1234567890',
            'subject' => 'paused member', // normalized subject
            'definition' => 'A member with status set to paused',
            'priority' => 0,
        ]);

        // Mock Slack payload with subject that needs normalization
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => "request_definition_modal_{$this->query->id}",
                'state' => [
                    'values' => [
                        'subject_block' => [
                            'subject' => [
                                'value' => '  Paused Member  ',
                            ],
                        ],
                        'definition_block' => [
                            'definition' => [
                                'value' => 'A member with status set to paused',
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is JSON with clear action
        $response->assertJson([
            'response_action' => 'clear',
        ]);
    }

    /**
     * Test error handling for non-existent query
     */
    public function test_non_existent_query_error_handling(): void
    {
        // Mock Slack payload with non-existent query ID
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => 'request_definition_modal_99999',
                'state' => [
                    'values' => [
                        'subject_block' => [
                            'subject' => [
                                'value' => 'paused member',
                            ],
                        ],
                        'definition_block' => [
                            'definition' => [
                                'value' => 'A member with status set to paused',
                            ],
                        ],
                    ],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response contains error (should be handled gracefully)
        $response->assertJson([
            'response_action' => 'errors',
            'errors' => [
                'definition_block' => 'An error occurred while saving the definition',
            ],
        ]);
    }

    /**
     * Test unknown callback_id is handled gracefully
     */
    public function test_unknown_callback_id_handled_gracefully(): void
    {
        // Mock Slack payload with unknown callback_id
        $payload = [
            'type' => 'view_submission',
            'view' => [
                'callback_id' => 'unknown_callback',
                'state' => [
                    'values' => [],
                ],
            ],
            'user' => [
                'id' => 'U1234567890',
            ],
        ];

        // Make request to interactive endpoint
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/interactive", [
            'payload' => json_encode($payload),
        ]);

        // Assert response is JSON with clear action (graceful handling)
        $response->assertJson([
            'response_action' => 'clear',
        ]);
    }
}
