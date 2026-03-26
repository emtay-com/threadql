<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommand;

/**
 * Test command for demonstrating CommandHandlerLocator functionality.
 */
class TestCommand implements DomainCommand
{
    public function __construct(
        public readonly string $message
    ) {
    }
}
