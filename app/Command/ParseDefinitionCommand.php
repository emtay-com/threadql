<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to parse a definition input string
 */
class ParseDefinitionCommand implements DomainCommand
{
    public function __construct(
        public readonly string $input,
    ) {
    }
}
