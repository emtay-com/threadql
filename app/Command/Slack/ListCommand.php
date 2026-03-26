<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Enums\ListCommandOptions;
use App\Infrastructure\Command\DomainCommand;

/**
 * Command to list definitions or tables
 */
class ListCommand implements DomainCommand
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userId,
        public readonly ?int $threadId,
        public readonly ListCommandOptions $option,
    ) {
    }
}
