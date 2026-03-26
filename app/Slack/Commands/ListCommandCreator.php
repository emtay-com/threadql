<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Command\Slack\ListCommand;
use App\Enums\ListCommandOptions;
use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;
use App\Models\Thread;
use InvalidArgumentException;

/**
 * Creates ListCommand instances for the /list subcommand.
 */
class ListCommandCreator implements CommandCreatorInterface
{
    /**
     * Create a ListCommand instance with validation.
     *
     * @param string $remainder The list option (e.g., "definitions", "tables")
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp
     * @param string $channelId The Slack channel ID
     * @param string|null $teamId The Slack team ID
     * @param Tenant $tenant The tenant context
     * @return ListCommand
     */
    public function create(
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): DomainCommand {
        $optionToken = trim($remainder);

        if (empty($optionToken)) {
            throw new InvalidArgumentException("Usage:\n/soong list definitions\n/soong list tables");
        }

        $option = ListCommandOptions::fromString($optionToken);
        if (! $option) {
            throw new InvalidArgumentException("Usage:\n/soong list definitions\n/soong list tables");
        }

        $threadId = $this->resolveThreadId($threadTs, $channelId);

        return new ListCommand(tenantId: $tenant->id, userId: $userId, threadId: $threadId, option: $option);
    }

    /**
     * Resolve thread ID from thread timestamp
     */
    private function resolveThreadId(?string $threadTs, string $channelId): ?int
    {
        if (! $threadTs) {
            return null;
        }

        $thread = Thread::where('thread_ts', $threadTs)
            ->where('channel_id', $channelId)
            ->first();

        return $thread?->id;
    }
}
