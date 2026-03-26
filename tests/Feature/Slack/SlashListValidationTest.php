<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class SlashListValidationTest extends TestCase
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
     * Test missing option prints usage message
     */
    public function test_missing_option_prints_usage_message(): void
    {
        $expectedMessage = "Usage:\n/soong list definitions\n/soong list tables";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test invalid option prints usage message
     */
    public function test_invalid_option_prints_usage_message(): void
    {
        $expectedMessage = "Usage:\n/soong list definitions\n/soong list tables";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list invalidoption',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test case insensitive option parsing
     */
    public function test_case_insensitive_option_parsing(): void
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
            'text' => 'list DEFINITIONS',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test whitespace around option is trimmed
     */
    public function test_whitespace_around_option_is_trimmed(): void
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
            'text' => 'list  tables  ',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test too many arguments shows usage message
     */
    public function test_too_many_arguments_shows_usage_message(): void
    {
        $expectedMessage = "Usage:\n/soong list definitions\n/soong list tables";

        $this->mock(SlackMessenger::class, function ($mock) use ($expectedMessage) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(Mockery::type(Tenant::class), 'C1234567890', 'U1234567890', $expectedMessage)
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'list definitions extra',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }
}
