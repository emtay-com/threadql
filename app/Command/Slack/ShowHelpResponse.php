<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from showing help information
 */
class ShowHelpResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly string $helpText,
    ) {
    }

    /**
     * Create a success response with help text
     */
    public static function success(string $helpText): self
    {
        return new self($helpText);
    }

    /**
     * Check if the operation was successful
     */
    public function isSuccess(): bool
    {
        return true;
    }

    /**
     * Get any errors that occurred
     */
    public function getErrors(): array
    {
        return [];
    }

    /**
     * Get the result data
     */
    public function getResult(): string
    {
        return $this->helpText;
    }
}
