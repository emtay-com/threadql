<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;
use App\Models\Query;

/**
 * Command to generate the follow-up prompt for a query
 */
class GenerateFollowUpPromptCommand implements DomainCommand
{
    public function __construct(
        public readonly int $queryId,
        public readonly int $tenantId
    ) {
    }
}
