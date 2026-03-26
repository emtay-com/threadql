<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

class CrawlTableSchemaCommand implements DomainCommand
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $datasourceId,
    ) {
    }
}
