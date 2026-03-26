<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from creating a definition
 */
class CreateDefinitionResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly bool $created,
        public readonly string $subject,
        public readonly string $definition,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Create a success response
     */
    public static function success(string $subject, string $definition): self
    {
        return new self(true, $subject, $definition);
    }

    /**
     * Create a duplicate response
     */
    public static function duplicate(string $subject, string $definition): self
    {
        return new self(false, $subject, $definition, 'Definition already exists');
    }

    /**
     * Check if the operation was successful
     */
    public function isSuccess(): bool
    {
        return $this->created;
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
    public function getResult(): array
    {
        return [
            'subject' => $this->subject,
            'definition' => $this->definition,
        ];
    }
}
