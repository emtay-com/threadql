<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Definition;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SlashDefinitionsTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable middleware for all tests in this class
        $this->withoutMiddleware(ValidateSlackSignature::class);

        $this->tenant = Tenant::factory()->create();
        Config::set('slack.default_tenant_id', $this->tenant->id);
    }

    /**
     * Test successful definition creation
     */
    public function test_valid_definition_creates_record_and_sends_success_message(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', '1234567890.123456', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define trial member = member with status 3',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);
    }

    /**
     * Test definition with 'is a' divider
     */
    public function test_definition_with_is_a_divider_works(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define chargeback is a refund created by the payment processor',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'chargeback',
            'definition' => 'refund created by the payment processor',
        ]);
    }

    /**
     * Test definition with 'is' divider
     */
    public function test_definition_with_is_divider_works(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define active is subscription.status_id = 1',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'active',
            'definition' => 'subscription.status_id = 1',
        ]);
    }

    /**
     * Test duplicate subject rejection
     */
    public function test_duplicate_subject_rejects_and_shows_existing_definition(): void
    {
        // Create existing definition
        Definition::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'A definition for "trial member" already exists: member with status 3'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define trial member = member with status 4',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        // Verify no new record was created
        $this->assertDatabaseCount('definitions', 1);
        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);
    }

    /**
     * Test empty input shows help message
     */
    public function test_empty_input_shows_help_message(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890',
                    "Definition syntax error. Use one of:\n".
                    "• /soong define <subject> = <definition>\n".
                    "• /soong define <subject> is a <definition>\n".
                    '• /soong define <subject> is <definition>')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        // Verify no record was created
        $this->assertDatabaseCount('definitions', 0);
    }

    /**
     * Test invalid syntax (no divider)
     */
    public function test_invalid_syntax_no_divider_shows_help_message(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890',
                    "Definition syntax error. Use one of:\n".
                    "• /soong define <subject> = <definition>\n".
                    "• /soong define <subject> is a <definition>\n".
                    '• /soong define <subject> is <definition>')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define trial member status 3',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        // Verify no record was created
        $this->assertDatabaseCount('definitions', 0);
    }

    /**
     * Test unknown subcommand
     */
    public function test_unknown_subcommand_shows_help_message(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Unknown subcommand. Use: /soong help for a list of commands'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'unknowncommand',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        // Verify no record was created
        $this->assertDatabaseCount('definitions', 0);
    }

    /**
     * Test divider preference order
     */
    public function test_divider_preference_order_respected(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        // This should match '=' first, not 'is a'
        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define test = is a definition',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'test',
            'definition' => 'is a definition',
        ]);
    }

    /**
     * Test subject normalization
     */
    public function test_subject_normalization_works(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define  TRIAL  MEMBER  = member with status 3',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);
    }

    /**
     * Test thread association when thread_ts is provided
     */
    public function test_thread_association_when_thread_ts_provided(): void
    {
        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('replyInThread')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', '1234567890.123456', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define trial member = member with status 3',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
            'thread_ts' => '1234567890.123456',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'thread_id' => $thread->id,
            'subject' => 'trial member',
            'definition' => 'member with status 3',
        ]);
    }

    /**
     * Test multiple dividers uses first one
     */
    public function test_multiple_dividers_uses_first_one_equals(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define test = definition = another',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        // Should use the first '=' as divider
        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'test',
            'definition' => 'definition = another',
        ]);
    }

    /**
     * Test multiple dividers with first one used
     */
    public function test_multiple_dividers_uses_first_one(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', 'Got it, thanks.')
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'define active country is a country where enabled=1',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('definitions', [
            'tenant_id' => $this->tenant->id,
            'user_id' => 'U1234567890',
            'subject' => 'active country',
            'definition' => 'country where enabled=1',
        ]);
    }

    /**
     * Test help subcommand works
     */
    public function test_help_subcommand_works(): void
    {
        $cmd = '/'.($this->tenant->bot_name ?? $this->tenant->name);
        $this->mock(SlackMessenger::class, function ($mock) use ($cmd) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890',
                    "Available commands:\n".
                    "• {$cmd} define <subject> = <definition>\n".
                    "• {$cmd} define <subject> is a <definition>\n".
                    "• {$cmd} define <subject> is <definition>\n".
                    "• {$cmd} list definitions - Show all business definitions\n".
                    "• {$cmd} list tables - Show all known tables\n".
                    "• {$cmd} help - Show this help message"
                )
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
}
