<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

class TestDatasourceConnectionCommand implements DomainCommand
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $datasourceId,
    ) {
    }
}
