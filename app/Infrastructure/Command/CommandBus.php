<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Psr\Log\LoggerInterface;

class CommandBus implements DomainCommandBus
{
    public function __construct(
        private readonly CommandHandlerLocator $commandHandlerLocator,
        private readonly LoggerInterface $logger
    ) {
    }

    public function dispatch(DomainCommand $command): DomainCommandResponse
    {
        /** @var DomainCommandHandler $commandHandler */
        $commandHandler = $this->commandHandlerLocator->get($command);

        $this->logger->debug('Dispatching command through command handler: '.get_class($command));

        return $commandHandler($command);
    }
}
