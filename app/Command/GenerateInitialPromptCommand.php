<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;
use App\Models\Query;

/**
 * Command to generate the initial prompt for a query
 */
class GenerateInitialPromptCommand implements DomainCommand
{
    public function __construct(
        public readonly int $queryId,
        public readonly int $tenantId
    ) {
    }
}
