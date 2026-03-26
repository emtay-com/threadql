<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Command\GenerateAppManifestCommand;
use App\Infrastructure\Command\DomainCommandBus;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Generate Slack App Manifest for a tenant
 */
class ThreadqlGenerateManifest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'threadql:manifest
                            {tenant_uuid : The UUID of the tenant}
                            {--base-url= : Base URL for the app (defaults to config app.url)}
                            {--name=threadql : App name}
                            {--bot=threadql : Bot display name}
                            {--out= : Output file path (defaults to stdout)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Slack App Manifest JSON for a specific tenant';

    public function __construct(
        private DomainCommandBus $commandBus,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantUuid = $this->argument('tenant_uuid');
        $baseUrl = $this->option('base-url') ?? config('app.url');
        $appName = $this->option('name');
        $botName = $this->option('bot');
        $outputPath = $this->option('out');

        // Validate tenant exists
        $tenant = Tenant::where('uuid', $tenantUuid)->first();
        if (! $tenant) {
            $this->error("Tenant with UUID '{$tenantUuid}' not found");

            return Command::FAILURE;
        }

        // Generate manifest
        $command = new GenerateAppManifestCommand(
            tenantUuid: $tenantUuid,
            baseUrl: $baseUrl,
            appName: $appName,
            botDisplayName: $botName
        );

        $response = $this->commandBus->dispatch($command);

        if (! $response->isSuccess()) {
            $this->error('Failed to generate manifest: '.implode(', ', $response->getErrors()));

            return Command::FAILURE;
        }

        $manifestJson = $response->getResult();

        // Output the manifest
        if ($outputPath) {
            file_put_contents($outputPath, $manifestJson);
            $this->info("Manifest written to: {$outputPath}");
        } else {
            $this->line($manifestJson);
        }

        return Command::SUCCESS;
    }
}
