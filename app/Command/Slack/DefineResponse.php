<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from defining a business term
 */
class DefineResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {
    }

    /**
     * Create a success response
     */
    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    /**
     * Create an error response
     */
    public static function error(string $message): self
    {
        return new self(false, $message);
    }

    /**
     * Check if the operation was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get any errors that occurred
     */
    public function getErrors(): array
    {
        return $this->success ? [] : [$this->message];
    }

    /**
     * Get the result data
     */
    public function getResult(): string
    {
        return $this->message;
    }
}
