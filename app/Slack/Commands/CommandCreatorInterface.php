<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;

/**
 * Interface for command creator strategies.
 * Each strategy is responsible for creating and validating a specific command type.
 */
interface CommandCreatorInterface
{
    /**
     * Create a command instance from the provided context.
     *
     * @param string $remainder The remainder text after the subcommand
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp (if in a thread)
     * @param string $channelId The Slack channel ID
     * @param string|null $teamId The Slack team/workspace ID
     * @param Tenant $tenant The tenant context
     * @return DomainCommand The created command instance
     */
    public function create(
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): DomainCommand;
}
