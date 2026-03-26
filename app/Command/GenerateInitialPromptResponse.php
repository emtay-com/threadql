<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;
use App\Models\LlmProvider;

/**
 * Response for GenerateInitialPromptCommand
 */
class GenerateInitialPromptResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly array $messages,
        public readonly LlmProvider $provider,
        public readonly string $modelName,
    ) {
    }

    public function isSuccess(): bool
    {
        return true;
    }

    public function getErrors(): array
    {
        return [];
    }

    public function getResult(): mixed
    {
        return $this->messages;
    }
}
