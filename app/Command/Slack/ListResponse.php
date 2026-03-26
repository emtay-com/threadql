<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from listing definitions or tables
 */
class ListResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly string $result,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Create a success response
     */
    public static function success(string $result): self
    {
        return new self($result);
    }

    /**
     * Create an error response
     */
    public static function error(string $reason): self
    {
        return new self('', $reason);
    }

    /**
     * Check if the operation was successful
     */
    public function isSuccess(): bool
    {
        return $this->reason === null;
    }

    /**
     * Get any errors that occurred
     */
    public function getErrors(): array
    {
        return $this->reason ? [$this->reason] : [];
    }

    /**
     * Get the result data
     */
    public function getResult(): string
    {
        return $this->result;
    }
}
