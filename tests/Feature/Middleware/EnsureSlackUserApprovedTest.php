<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\TenantSettingEnum;
use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Datasource;
use App\Models\SlackUser;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class EnsureSlackUserApprovedTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Datasource::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->withoutMiddleware(ValidateSlackSignature::class);

        Queue::fake();
    }

    public function test_approved_user_can_access_events_endpoint(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->andReturn([
                    'ts' => '1234567890.123456',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => 'event_callback',
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => '<@UBOT> show me sales data',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // User was created and approved
        $this->assertDatabaseHas('slack_users', [
            'tenant_id' => $this->tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => true,
        ]);
    }

    public function test_unapproved_user_is_blocked_on_events_endpoint(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Your account has not been approved yet. Please contact your workspace administrator to get access.'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);

            $mock->shouldNotReceive('replyInThread');
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => 'event_callback',
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => '<@UBOT> show me sales data',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // User was created but not approved
        $this->assertDatabaseHas('slack_users', [
            'tenant_id' => $this->tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => false,
        ]);

        // No query should have been created
        $this->assertDatabaseMissing('queries', [
            'channel_id' => 'C1234567890',
        ]);
    }

    public function test_existing_unapproved_user_is_blocked_on_subsequent_request(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        // Pre-create an unapproved user (e.g., admin revoked approval)
        SlackUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => false,
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Your account has not been approved yet. Please contact your workspace administrator to get access.'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);

            $mock->shouldNotReceive('replyInThread');
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => 'event_callback',
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => '<@UBOT> show me sales data',
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);
    }

    public function test_url_verification_bypasses_approval_check(): void
    {
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => 'url_verification',
            'challenge' => 'test_challenge_123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'challenge' => 'test_challenge_123',
            ]);

        // No slack user should have been created
        $this->assertDatabaseCount('slack_users', 0);
    }

    public function test_unapproved_user_is_blocked_on_slash_commands(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Your account has not been approved yet. Please contact your workspace administrator to get access.'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define trial member = member with status 3',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // User was created but not approved
        $this->assertDatabaseHas('slack_users', [
            'tenant_id' => $this->tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => false,
        ]);

        // No definition should have been created
        $this->assertDatabaseMissing('definitions', [
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_approved_user_can_access_slash_commands(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'help',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    public function test_unapproved_user_is_blocked_on_interactive_endpoint(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Your account has not been approved yet. Please contact your workspace administrator to get access.'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $payload = json_encode([
            'type' => 'block_actions',
            'user' => [
                'id' => 'U1234567890',
            ],
            'channel' => [
                'id' => 'C1234567890',
            ],
            'actions' => [
                [
                    'action_id' => 'yes_button_1',
                    'value' => 'yes',
                ],
            ],
        ]);

        $response = $this->post(
            "/api/{$this->tenant->uuid}/slack/interactive",
            [
                'payload' => $payload,
            ],
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]
        );

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);
    }

    public function test_notification_failure_does_not_break_blocking(): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->andThrow(new \Exception('Slack API error'));
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/events", [
            'type' => 'event_callback',
            'event_id' => 'Ev1234567890',
            'team_id' => 'T1234567890',
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1234567890',
                'ts' => '1234567890.123456',
                'thread_ts' => '1234567890.123456',
                'user' => 'U1234567890',
                'text' => '<@UBOT> show me sales data',
            ],
        ]);

        // Should still block even if notification fails
        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
            ]);

        // No query should have been created
        $this->assertDatabaseMissing('queries', [
            'channel_id' => 'C1234567890',
        ]);
    }
}
