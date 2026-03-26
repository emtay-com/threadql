<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Results\SelectResult;
use App\Command\Results\SelectResultWithPagination;
use App\Infrastructure\Command\DomainCommandResponse;
use JsonSerializable;

/**
 * Response for ExecuteParameterizedSelectCommand
 */
class ExecuteParameterizedSelectCommandResponse implements DomainCommandResponse, JsonSerializable
{
    public function __construct(
        private readonly SelectResult $result,
        private readonly array $errors = []
    ) {
    }

    public function isSuccess(): bool
    {
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return SelectResult|SelectResultWithPagination
     */
    public function getResult(): SelectResult
    {
        return $this->result;
    }

    public static function success(SelectResult $result): self
    {
        return new self($result);
    }

    public static function error(string $error): self
    {
        return new self(result: SelectResult::empty(), errors: [$error]);
    }

    public function jsonSerialize(): array
    {
        return [
            'errors' => $this->errors,
            'result' => $this->result->jsonSerialize(),
        ];
    }
}
