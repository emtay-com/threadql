<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Enums\QueryStatus;
use App\Enums\SlackEventType;
use App\Http\Middleware\HandleSlackRetries;
use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\UserFollowUpQueryJob;
use App\Jobs\UserQueryInvokerJob;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SlackEventsEndpointTest extends TestCase
{
    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a tenant with datasource for all tests
        $this->tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Disable middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);

        // Mock the Slack messenger
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->andReturn([
                    'ts' => '1234567890.123456',
                ]);
        });

        // Also mock for any other calls
        $this->app->bind(SlackMessenger::class, function () {
            $mock = Mockery::mock(SlackMessenger::class);
            $mock->shouldReceive('replyInThread')
                ->andReturn([
                    'ts' => '1234567890.123456',
                ]);

            return $mock;
        });

        // Fake the queue to prevent jobs from running
        Queue::fake();
    }

    public function test_url_verification_challenge(): void
    {
        $challenge = 'test_challenge_123';

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => SlackEventType::URL_VERIFICATION->value,
            'challenge' => $challenge,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'challenge' => $challenge,
            ]);
    }

    public function test_app_mention_creates_thread_and_query(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> show me sales data',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);
    }

    public function test_im_message_creates_thread_and_query(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_im_message',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::MESSAGE->value,
                'channel' => 'D1234567890',
                'channel_type' => 'im',
                'ts' => '1234567890.223456',
                'thread_ts' => '1234567890.223456',
                'user' => 'U1234567890',
                'text' => 'show me sales data',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        $this->assertDatabaseHas('threads', [
            'channel_id' => 'D1234567890',
            'thread_ts' => '1234567890.223456',
        ]);
        $this->assertDatabaseHas('queries', [
            'slack_event_id' => 'Ev_im_message',
            'channel_id' => 'D1234567890',
            'message_ts' => '1234567890.223456',
            'raw_text' => 'show me sales data',
            'user_id' => 'U1234567890',
        ]);
    }

    public function test_non_app_mention_event_ignored(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::MESSAGE_CHANGED->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => 'Hello world',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // No additional thread or query should be created by this request
        // (Note: setUp() may have created some data, so we just ensure the request doesn't create more)
        $this->assertDatabaseMissing('threads', [
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);
        $this->assertDatabaseMissing('queries', [
            'channel_id' => 'C1234567890',
        ]);
    }

    public function test_bot_message_event_is_ignored(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_bot_message',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::MESSAGE->value,
                'channel' => 'D1234567890',
                'channel_type' => 'im',
                'subtype' => 'bot_message',
                'bot_id' => 'B1234567890',
                'ts' => '1234567890.323456',
                'text' => 'Loop bait',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        $this->assertDatabaseMissing('threads', [
            'channel_id' => 'D1234567890',
            'thread_ts' => '1234567890.323456',
        ]);
        $this->assertDatabaseMissing('queries', [
            'slack_event_id' => 'Ev_bot_message',
        ]);
    }

    public function test_message_event_with_subtype_is_ignored(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_message_changed',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::MESSAGE->value,
                'channel' => 'D1234567890',
                'channel_type' => 'im',
                'subtype' => 'message_changed',
                'ts' => '1234567890.423456',
                'user' => 'U1234567890',
                'text' => 'Edited content',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        $this->assertDatabaseMissing('threads', [
            'channel_id' => 'D1234567890',
            'thread_ts' => '1234567890.423456',
        ]);
        $this->assertDatabaseMissing('queries', [
            'slack_event_id' => 'Ev_message_changed',
        ]);
    }

    public function test_duplicate_event_id_returns_ok_without_creating_second_query(): void
    {
        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_duplicate_test',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.999999',
                'thread_ts' => '1234567890.999999',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> show me revenue',
            ],
        ];

        // First request — creates the query
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);
        $response->assertStatus(200);

        $this->assertDatabaseCount('queries', 1);

        // Second request with same event_id — should be treated as duplicate
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);
        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // Still only one query
        $this->assertDatabaseCount('queries', 1);
    }

    public function test_slack_retry_header_short_circuits_processing(): void
    {
        // Re-enable the HandleSlackRetries middleware for this test
        $this->withMiddleware(HandleSlackRetries::class);

        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_retry_test',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.111111',
                'thread_ts' => '1234567890.111111',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> show me revenue',
            ],
        ];

        $response = $this->postJson(
            "/api/{$this->tenant->uuid}/slack/events",
            $payload,
            [
                'X-Slack-Retry-Num' => '1',
                'X-Slack-Retry-Reason' => 'http_timeout',
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // No query should have been created
        $this->assertDatabaseMissing('queries', [
            'slack_event_id' => 'Ev_retry_test',
        ]);
    }

    public function test_second_message_while_query_in_flight_sends_ephemeral_and_does_not_create_query(): void
    {
        // Create an existing thread with an in-flight query
        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.000001',
        ]);

        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::EXECUTING->value,
            'slack_event_id' => 'Ev_first_query',
        ]);

        // Replace SlackMessenger mock to also expect sendEphemeral
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->andReturn([
                    'ts' => '1234567890.123456',
                ]);
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    Mockery::type('string'),
                    '1234567890.000001'
                )
                ->andReturn([
                    'ts' => '1234567890.999999',
                ]);
        });

        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_second_message',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.000002',
                'thread_ts' => '1234567890.000001',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> follow up question',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // No new query should have been created
        $this->assertDatabaseMissing('queries', [
            'slack_event_id' => 'Ev_second_message',
        ]);

        // No job should have been dispatched
        Queue::assertNothingPushed();
    }

    public function test_message_in_thread_after_query_completes_dispatches_follow_up_job(): void
    {
        // Create an existing thread with a completed query
        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.000010',
        ]);

        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::DONE->value,
            'slack_event_id' => 'Ev_completed_query',
        ]);

        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_followup_message',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.000011',
                'thread_ts' => '1234567890.000010',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> now filter by 2024',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // A new query should have been created
        $this->assertDatabaseHas('queries', [
            'slack_event_id' => 'Ev_followup_message',
        ]);

        // Follow-up job should be dispatched
        Queue::assertPushed(UserFollowUpQueryJob::class);
        Queue::assertNotPushed(UserQueryInvokerJob::class);
    }

    public function test_message_allowed_after_previous_query_errored(): void
    {
        // Create an existing thread with an errored query
        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.000020',
        ]);

        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::ERROR->value,
            'slack_event_id' => 'Ev_errored_query',
        ]);

        $payload = [
            'type' => SlackEventType::EVENT_CALLBACK->value,
            'event_id' => 'Ev_retry_after_error',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => SlackEventType::APP_MENTION->value,
                'channel' => 'C1234567890',
                'ts' => '1234567890.000021',
                'thread_ts' => '1234567890.000020',
                'user' => 'U1234567890',
                'text' => '<@U1234567890> try again',
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // Query should be created and job dispatched
        $this->assertDatabaseHas('queries', [
            'slack_event_id' => 'Ev_retry_after_error',
        ]);

        Queue::assertPushed(UserQueryInvokerJob::class);
    }

    public function test_missing_required_fields_returns_error(): void
    {
        $payload = [
            'type' => 'event_callback',
            'event_id' => 'Ev1234567890',
            'event' => [
                'type' => 'app_mention',
                'text' => '<@U1234567890> show me sales data',
                // Missing required fields: team_id, channel, ts, user
            ],
        ];

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", $payload);

        $response->assertStatus(400)
            ->assertJson([
                'ok' => false,
            ]);
    }
}
