<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\SlackClientFactory;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Update Slack credentials for a tenant
 */
class UpdateSlackCredentials extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'slack:update-credentials {--tenant-id= : The tenant ID to update}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update Slack credentials for a tenant and test the integration';

    public function __construct(
        private readonly SlackClientFactory $slackFactory
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant-id');

        if (! $tenantId) {
            $tenantId = $this->ask('Enter tenant ID');
        }

        if (! is_numeric($tenantId)) {
            $this->error('Tenant ID must be a number');

            return Command::FAILURE;
        }

        try {
            $tenant = $this->findTenant((int) $tenantId);
        } catch (EntityNotFoundException $e) {
            $this->error("Tenant with ID {$tenantId} not found");

            return Command::FAILURE;
        }

        $this->info("Updating Slack credentials for tenant: {$tenant->name} (ID: {$tenant->id})");
        $this->newLine();

        // Collect Slack credentials
        $credentials = $this->collectSlackCredentials();

        // Update tenant with credentials
        $this->updateTenantCredentials($tenant, $credentials);

        $this->info('✅ Slack credentials updated successfully!');
        $this->newLine();

        // Test Slack integration
        $this->info('🔍 Testing Slack API integration...');

        if ($this->testSlackIntegration($tenant)) {
            $this->info('✅ Slack integration test passed!');
            $this->newLine();
            $this->info('🎉 Tenant Slack configuration is complete and working!');

            return Command::SUCCESS;
        } else {
            $this->error('❌ Slack integration test failed. Please check your credentials.');

            return Command::FAILURE;
        }
    }

    /**
     * Find tenant by ID
     */
    private function findTenant(int $tenantId): Tenant
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            throw new EntityNotFoundException('Tenant', (string) $tenantId);
        }

        return $tenant;
    }

    /**
     * Collect Slack credentials from user input
     */
    private function collectSlackCredentials(): array
    {
        $this->info('Please provide your Slack app credentials:');
        $this->newLine();

        return [
            'slack_app_id' => $this->ask('Slack App ID (e.g., A0123456789)'),
            'slack_client_id' => $this->ask('Slack Client ID (e.g., 123456789.987654321)'),
            'slack_bot_token' => $this->ask('Slack Bot Token (e.g., xoxb-...)'),
            'slack_signing_secret' => $this->ask('Slack Signing Secret'),
            'slack_verification_token' => $this->ask('Slack Verification Token (optional)', null),
        ];
    }

    /**
     * Update tenant with Slack credentials
     */
    private function updateTenantCredentials(Tenant $tenant, array $credentials): void
    {
        $tenant->fill($credentials);
        $tenant->save();

        Log::info('Slack credentials updated for tenant', [
            'tenant_id' => $tenant->id,
            'has_app_id' => ! empty($credentials['slack_app_id']),
            'has_client_id' => ! empty($credentials['slack_client_id']),
            'has_bot_token' => ! empty($credentials['slack_bot_token']),
            'has_signing_secret' => ! empty($credentials['slack_signing_secret']),
            'has_verification_token' => ! empty($credentials['slack_verification_token']),
        ]);
    }

    /**
     * Test Slack API integration
     */
    private function testSlackIntegration(Tenant $tenant): bool
    {
        try {
            if (! $tenant->slack_bot_token) {
                $this->warn('No bot token configured - skipping API test');

                return false;
            }

            $client = $this->slackFactory->create($tenant->slack_bot_token);

            // Test the API connection with api.test
            $response = $client->apiTest();

            if ($response->getOk()) {
                $this->info('API test successful. Slack API connection verified.');

                return true;
            } else {
                $error = method_exists($response, 'getError')
                    ? ($response->getError() ?? 'Unknown error')
                    : 'Unknown error';
                $this->error('API test failed: '.$error);

                return false;
            }

        } catch (\Exception $e) {
            $this->error('Slack API test failed: '.$e->getMessage());
            Log::error('Slack API test failed', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
