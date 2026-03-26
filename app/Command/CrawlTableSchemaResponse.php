<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;

class CrawlTableSchemaResponse implements DomainCommandResponse
{
    /**
     * @param  array<int, string>  $tablesProcessed
     * @param  array<int, array{table_name: string, error: string}>  $failures
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $tablesProcessed = [],
        public readonly array $failures = [],
        public readonly ?string $errorMessage = null,
    ) {
    }

    public static function completed(array $tablesProcessed, array $failures = []): self
    {
        return new self(success: true, tablesProcessed: $tablesProcessed, failures: $failures);
    }

    public static function failed(string $errorMessage): self
    {
        return new self(success: false, errorMessage: $errorMessage);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<int, string>
     */
    public function getErrors(): array
    {
        if ($this->errorMessage !== null) {
            return [$this->errorMessage];
        }

        return array_map(fn (array $f) => "{$f['table_name']}: {$f['error']}", $this->failures);
    }

    public function getResult(): mixed
    {
        return [
            'success' => $this->success,
            'tables_processed' => $this->tablesProcessed,
            'failures' => $this->failures,
            'error' => $this->errorMessage,
        ];
    }
}
