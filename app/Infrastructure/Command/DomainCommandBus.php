<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

interface DomainCommandBus
{
    public function dispatch(DomainCommand $command): DomainCommandResponse;
}
