<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to create a new definition
 */
class CreateDefinitionCommand implements DomainCommand
{
    public function __construct(
        public readonly int $tenantId,
        public readonly string $userId,
        public readonly ?int $threadId,
        public readonly string $subject,
        public readonly string $definition,
        public readonly int $priority = 0,
    ) {
    }
}
