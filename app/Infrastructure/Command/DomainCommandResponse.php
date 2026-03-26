<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

interface DomainCommandResponse
{
    public function isSuccess(): bool;

    /**
     * @return array<int, string>
     */
    public function getErrors(): array;

    public function getResult(): mixed;
}
