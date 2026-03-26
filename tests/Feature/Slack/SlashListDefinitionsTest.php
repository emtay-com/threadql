<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SlashListDefinitionsTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable middleware for all tests in this class
        $this->withoutMiddleware(\App\Http\Middleware\ValidateSlackSignature::class);

        $this->tenant = Tenant::factory()->create();
        Config::set('slack.default_tenant_id', $this->tenant->id);
    }

    /**
     * Test listing definitions returns formatted output
     */
    public function test_list_definitions_returns_formatted_output(): void
    {
        // Create test definitions
        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'trial member',
            'definition' => 'member with status 3',
            'priority' => 0,
        ]);

        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'active members',
            'definition' => 'member with status 1',
            'priority' => 10,
        ]);

        $expectedMessage = "Definitions (tenant {$this->tenant->id})\n```\nactive members => member with status 1\ntrial member => member with status 3\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list definitions',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test listing definitions with no definitions shows empty message
     */
    public function test_list_definitions_with_no_definitions_shows_empty_message(): void
    {
        $expectedMessage = "Definitions (tenant {$this->tenant->id})\n```\nNo definitions found for this tenant.\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list definitions',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test list definitions in thread replies in thread
     */
    public function test_list_definitions_in_thread_replies_in_thread(): void
    {
        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);

        $expectedMessage = "Definitions (tenant {$this->tenant->id})\n```\ntrial member => member with status 3\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('replyInThread')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', '1234567890.123456', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list definitions',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test definitions are ordered by priority desc, then subject asc
     */
    public function test_definitions_ordered_by_priority_desc_then_subject_asc(): void
    {
        // Create definitions with different priorities
        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'zebra',
            'definition' => 'striped animal',
            'priority' => 0,
        ]);

        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'apple',
            'definition' => 'fruit',
            'priority' => 10,
        ]);

        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'subject' => 'banana',
            'definition' => 'yellow fruit',
            'priority' => 10,
        ]);

        $expectedMessage = "Definitions (tenant {$this->tenant->id})\n```\napple => fruit\nbanana => yellow fruit\nzebra => striped animal\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list definitions',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }
}
