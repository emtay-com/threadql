<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ValidateSlackSignature;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Tenant;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Test the slash command to toggle DEBUG setting
 */
final class NoSlashCommandToToggleDebugTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateSlackSignature::class);

        $this->tenant = Tenant::factory()->create();
        Config::set('slack.default_tenant_id', $this->tenant->id);
    }

    /**
     * Test that /soong debug on succeeds
     */
    public function test_debug_on_command_returns_unknown_subcommand(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    Mockery::pattern('/Debug mode is now \*\*ON\*\*/')
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'debug on',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test that /soong debug off succeeds
     */
    public function test_debug_off_command_returns_unknown_subcommand(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    Mockery::pattern('/Debug mode is now \*\*OFF\*\*/')
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'debug off',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }

    /**
     * Test that /soong debug (without arguments) returns a validation error
     */
    public function test_debug_command_without_args_returns_unknown_subcommand(): void
    {
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldReceive('sendEphemeral')
                ->once()
                ->with(
                    Mockery::type(Tenant::class),
                    'C1234567890',
                    'U1234567890',
                    'Try "/soong debug on" or "/soong debug off"'
                )
                ->andReturn([
                    'ts' => '1234567890.123457',
                ]);
        });

        $response = $this->postJson("/api/{$this->tenant->uuid}/slack/commands", [
            'text' => 'debug',
            'team_id' => 'T1234567890',
            'channel_id' => 'C1234567890',
            'user_id' => 'U1234567890',
        ]);

        $response->assertStatus(204);
    }
}
