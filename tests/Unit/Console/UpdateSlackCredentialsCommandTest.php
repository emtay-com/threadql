<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\UpdateSlackCredentials;
use App\Infrastructure\Slack\SlackClientFactory;
use App\Models\Tenant;
use JoliCode\Slack\Api\Client;
use Mockery;
use Tests\TestCase;

/**
 * Test the UpdateSlackCredentials command
 */
class UpdateSlackCredentialsCommandTest extends TestCase
{
    private SlackClientFactory $mockFactory;

    private Client $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Slack client factory
        $this->mockFactory = Mockery::mock(SlackClientFactory::class);
        $this->mockClient = Mockery::mock(Client::class);

        $this->app->instance(SlackClientFactory::class, $this->mockFactory);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Test that the command fails with non-existent tenant
     */
    public function test_command_fails_with_non_existent_tenant(): void
    {
        $this->artisan('slack:update-credentials', [
            '--tenant-id' => 99999,
        ])
            ->expectsOutput('Tenant with ID 99999 not found')
            ->assertFailed();
    }

    /**
     * Test that the command successfully updates credentials and tests integration
     */
    public function test_command_updates_credentials_and_tests_integration(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
        ]);

        // Mock successful API test
        $mockResponse = Mockery::mock();
        $mockResponse->shouldReceive('getOk')
            ->andReturn(true);
        $mockResponse->shouldReceive('getUser')
            ->andReturn('TestBot');

        $this->mockClient->shouldReceive('apiTest')
            ->andReturn($mockResponse);
        $this->mockFactory->shouldReceive('create')
            ->andReturn($this->mockClient);

        // Mock user input
        $this->artisan('slack:update-credentials', [
            '--tenant-id' => $tenant->id,
        ])
            ->expectsQuestion('Slack App ID (e.g., A0123456789)', 'A0123456789')
            ->expectsQuestion('Slack Client ID (e.g., 123456789.987654321)', '123456789.987654321')
            ->expectsQuestion('Slack Bot Token (e.g., xoxb-...)', 'xoxb-test-token')
            ->expectsQuestion('Slack Signing Secret', 'test-signing-secret')
            ->expectsQuestion('Slack Verification Token (optional)', null)
            ->expectsOutputToContain('Updating Slack credentials for tenant')
            ->expectsOutputToContain('Slack credentials updated successfully')
            ->expectsOutputToContain('Testing Slack API integration')
            ->expectsOutputToContain('API test successful')
            ->expectsOutputToContain('Slack integration test passed')
            ->expectsOutputToContain('Tenant Slack configuration is complete')
            ->assertSuccessful();

        // Verify credentials were stored (they should be encrypted)
        $tenant->refresh();
        $this->assertNotEmpty($tenant->slack_app_id);
        $this->assertNotEmpty($tenant->slack_client_id);
        $this->assertNotEmpty($tenant->slack_bot_token);
        $this->assertNotEmpty($tenant->slack_signing_secret);
        $this->assertNull($tenant->slack_verification_token);

        // Verify they can be decrypted
        $this->assertEquals('A0123456789', $tenant->slack_app_id);
        $this->assertEquals('123456789.987654321', $tenant->slack_client_id);
        $this->assertEquals('xoxb-test-token', $tenant->slack_bot_token);
        $this->assertEquals('test-signing-secret', $tenant->slack_signing_secret);
    }

    /**
     * Test that the command fails when API test fails
     */
    public function test_command_fails_when_api_test_fails(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'Test Tenant',
        ]);

        // Mock failed API test
        $mockResponse = Mockery::mock();
        $mockResponse->shouldReceive('getOk')
            ->andReturn(false);
        $mockResponse->shouldReceive('getError')
            ->andReturn('invalid_auth');

        $this->mockClient->shouldReceive('apiTest')
            ->andReturn($mockResponse);
        $this->mockFactory->shouldReceive('create')
            ->andReturn($this->mockClient);

        $this->artisan('slack:update-credentials', [
            '--tenant-id' => $tenant->id,
        ])
            ->expectsQuestion('Slack App ID (e.g., A0123456789)', 'A0123456789')
            ->expectsQuestion('Slack Client ID (e.g., 123456789.987654321)', '123456789.987654321')
            ->expectsQuestion('Slack Bot Token (e.g., xoxb-...)', 'xoxb-invalid-token')
            ->expectsQuestion('Slack Signing Secret', 'test-signing-secret')
            ->expectsQuestion('Slack Verification Token (optional)', null)
            ->expectsOutput('❌ Slack integration test failed. Please check your credentials.')
            ->assertFailed();
    }
}
