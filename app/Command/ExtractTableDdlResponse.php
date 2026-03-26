<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;
use App\Models\Table;

/**
 * Response for ExtractTableDdlCommand.
 */
class ExtractTableDdlResponse implements DomainCommandResponse
{
    public function __construct(
        private readonly bool $success,
        private readonly array $errors,
        private readonly ?Table $result
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

    public function getResult(): ?Table
    {
        return $this->result;
    }
}
