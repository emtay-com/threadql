<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Test command response for demonstrating CommandHandlerLocator functionality.
 */
class TestCommandResponse implements DomainCommandResponse
{
    public function __construct(
        private readonly bool $success,
        private readonly array $errors,
        private readonly mixed $result
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }
}
