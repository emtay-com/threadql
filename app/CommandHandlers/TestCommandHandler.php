<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\TestCommand;
use App\Command\TestCommandResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Test command handler for demonstrating CommandHandlerLocator functionality.
 */
class TestCommandHandler implements DomainCommandHandler
{
    public function __invoke(TestCommand $command): DomainCommandResponse
    {
        return new TestCommandResponse(success: true, errors: [], result: 'Processed: '.$command->message);
    }
}
