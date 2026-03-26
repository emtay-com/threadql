<?php

declare(strict_types=1);

namespace App\Support\Ledger;

use App\Models\ToolCall;

class SqlCallSummarizer
{
    public function __construct(
        private readonly ?ToolCall $toolCall = null,
    ) {
    }

    public function summarize(): ?string
    {
        if (! $this->toolCall) {
            return null;
        }

        return ' payload: '.json_encode($this->toolCall->request_payload, JSON_PRETTY_PRINT).PHP_EOL.
               'response stats: '.json_encode($this->toolCall->response_payload, JSON_PRETTY_PRINT).PHP_EOL;
    }
}
