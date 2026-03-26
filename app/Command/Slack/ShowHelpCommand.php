<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to show help information
 */
class ShowHelpCommand implements DomainCommand
{
    public function __construct(
        public readonly string $userId,
        public readonly ?int $threadId,
        public readonly string $slashCommand = '/threadql',
    ) {
    }
}
