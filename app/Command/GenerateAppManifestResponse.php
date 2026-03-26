<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

/**
 * Response from generating a Slack App Manifest
 */
class GenerateAppManifestResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $json,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Create a success response
     */
    public static function success(string $json): self
    {
        return new self(true, $json);
    }

    /**
     * Create a failure response
     */
    public static function failure(string $error): self
    {
        return new self(false, null, $error);
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
        return $this->error ? [$this->error] : [];
    }

    /**
     * Get the result data
     */
    public function getResult(): ?string
    {
        return $this->json;
    }
}
