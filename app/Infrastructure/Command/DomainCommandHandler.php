<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

/**
 * Base interface for all domain command handlers.
 *
 * This interface is intentionally flexible to allow handlers to accept
 * specific command types while still providing a common contract.
 *
 * Handlers should implement this interface with their specific command type
 * as the parameter type for the __invoke method.
 *
 * @method mixed __invoke(object $command)
 */
interface DomainCommandHandler
{
    public const HANDLER_NAMESPACE = 'App\\CommandHandlers';
}
