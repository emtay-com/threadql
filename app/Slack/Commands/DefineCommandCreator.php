<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Command\Slack\DefineCommand;
use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;

/**
 * Creates DefineCommand instances for the /define subcommand.
 */
class DefineCommandCreator implements CommandCreatorInterface
{
    /**
     * Create a DefineCommand instance.
     *
     * @param string $remainder The definition text
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp
     * @param string $channelId The Slack channel ID
     * @param string|null $teamId The Slack team ID
     * @param Tenant $tenant The tenant context
     * @return DefineCommand
     */
    public function create(
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): DomainCommand {
        return new DefineCommand($remainder, $userId, $threadTs, $channelId);
    }
}
