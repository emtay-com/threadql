<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from parsing a definition input
 */
class ParseDefinitionResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $subject = null,
        public readonly ?string $definition = null,
        public readonly ?string $errorMessage = null,
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
     * Create an error response for invalid syntax
     */
    public static function invalidSyntax(): self
    {
        return new self(
            false,
            errorMessage: "Definition syntax error. Use one of:\n".
                "• /soong define <subject> = <definition>\n".
                "• /soong define <subject> is a <definition>\n".
                '• /soong define <subject> is <definition>'
        );
    }

    /**
     * Create an error response for empty input
     */
    public static function emptyInput(): self
    {
        return new self(
            false,
            errorMessage: "Definition syntax error. Use one of:\n".
                "• /soong define <subject> = <definition>\n".
                "• /soong define <subject> is a <definition>\n".
                '• /soong define <subject> is <definition>'
        );
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
        return $this->errorMessage ? [$this->errorMessage] : [];
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
