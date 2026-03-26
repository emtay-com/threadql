<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to execute a parameterized SELECT query against a tenant's datasource.
 */
class ExecuteParameterizedSelectCommand implements DomainCommand
{
    public function __construct(
        public int $queryId,
        public string $sql,
        public array $parameters = [],
        public ?int $rowLimit = null
    ) {
    }
}
