<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Infrastructure\Command\DomainCommandHandler;

/**
 * Test command handler interface for demonstrating interface resolution.
 *
 * By extending DomainCommandHandler, this interface inherits the __invoke method signature
 * and any implementation automatically becomes a valid command handler.
 */
interface TestCommandHandlerInterface extends DomainCommandHandler
{
}
