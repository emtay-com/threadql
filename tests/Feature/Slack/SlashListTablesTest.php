<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SlashListTablesTest extends TestCase
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
     * Test listing tables returns formatted output with priorities
     */
    public function test_list_tables_returns_formatted_output_with_priorities(): void
    {
        // Create test tables with different priorities
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'country',
            'priority' => 10,
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'members',
            'priority' => 0,
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'subscriptions',
            'priority' => 5,
        ]);

        $expectedMessage = "Tables (tenant {$this->tenant->id})\n```\ncountry (priority: 10)\nsubscriptions (priority: 5)\nmembers\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list tables',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test listing tables with no tables shows empty message
     */
    public function test_list_tables_with_no_tables_shows_empty_message(): void
    {
        $expectedMessage = "Tables (tenant {$this->tenant->id})\n```\nNo tables found for this tenant.\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list tables',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test list tables in thread replies in thread
     */
    public function test_list_tables_in_thread_replies_in_thread(): void
    {
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'country',
            'priority' => 10,
        ]);

        $expectedMessage = "Tables (tenant {$this->tenant->id})\n```\ncountry (priority: 10)\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('replyInThread')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', '1234567890.123456', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list tables',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test tables are ordered by priority desc, then name asc
     */
    public function test_tables_ordered_by_priority_desc_then_name_asc(): void
    {
        // Create tables with different priorities
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'zebra',
            'priority' => 0,
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'apple',
            'priority' => 10,
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'banana',
            'priority' => 10,
        ]);

        $expectedMessage = "Tables (tenant {$this->tenant->id})\n```\napple (priority: 10)\nbanana (priority: 10)\nzebra\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list tables',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test tables with zero priority don't show priority suffix
     */
    public function test_tables_with_zero_priority_dont_show_priority_suffix(): void
    {
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'members',
            'priority' => 0,
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'country',
            'priority' => 0,
        ]);

        $expectedMessage = "Tables (tenant {$this->tenant->id})\n```\ncountry\nmembers\n```";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list tables',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }
}
