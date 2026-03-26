<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

class TestDatasourceConnectionResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly bool $connected,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function success(): self
    {
        return new self(connected: true);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(connected: false, errorMessage: $errorMessage);
    }

    public function isSuccess(): bool
    {
        return $this->connected;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        if ($this->connected) {
            return [];
        }

        return [$this->errorMessage ?? 'Connection failed'];
    }

    public function getResult(): mixed
    {
        return [
            'connected' => $this->connected,
            'error' => $this->errorMessage,
        ];
    }
}
