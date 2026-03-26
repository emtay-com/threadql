<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to generate a Slack App Manifest for a specific tenant
 */
class GenerateAppManifestCommand implements DomainCommand
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $baseUrl,
        public readonly string $appName = 'threadql',
        public readonly string $botDisplayName = 'threadql',
        public readonly string $slashCommand = '/threadql'
    ) {
    }
}
