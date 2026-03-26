<?php

declare(strict_types=1);

namespace App\Command;

use App\Infrastructure\Command\DomainCommandResponse;
use App\Models\LlmProvider;

/**
 * Response for GenerateFollowUpPromptCommand
 */
class GenerateFollowUpPromptResponse implements DomainCommandResponse
{
    public function __construct(
        public readonly array $messages,
        public readonly LlmProvider $provider,
        public readonly string $modelName,
        public readonly ?int $lastSqlCallId = null
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
