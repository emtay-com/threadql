<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Command\Slack\ShowHelpCommand;
use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;

/**
 * Creates ShowHelpCommand instances for the /help subcommand.
 */
class ShowHelpCommandCreator implements CommandCreatorInterface
{
    /**
     * Create a ShowHelpCommand instance.
     *
     * @param string $remainder The remainder text (unused for help)
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp (unused for help)
     * @param string $channelId The Slack channel ID (unused for help)
     * @param string|null $teamId The Slack team ID (unused for help)
     * @param Tenant $tenant The tenant context (unused for help)
     * @return ShowHelpCommand
     */
    public function create(
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): DomainCommand {
        $slashCommand = '/'.($tenant->bot_name ?? $tenant->name);

        return new ShowHelpCommand($userId, null, $slashCommand);
    }
}
