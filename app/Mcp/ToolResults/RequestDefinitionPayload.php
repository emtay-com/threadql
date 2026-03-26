<?php

declare(strict_types=1);

namespace App\Mcp\ToolResults;

use JsonSerializable;

/**
 * Payload for request_definition MCP tool responses
 */
class RequestDefinitionPayload implements JsonSerializable
{
    private bool $ok;

    private ?string $status;

    private ?int $queryId;

    private ?string $subject;

    private ?string $message;

    private ?string $error;

    private function __construct()
    {
        $this->ok = true;
        $this->status = null;
        $this->queryId = null;
        $this->subject = null;
        $this->message = null;
        $this->error = null;
    }

    /**
     * Factory method for successful pending response
     */
    public static function pending(
        int $queryId,
        string $subject,
        string $message = 'Definition requested; awaiting user input.'
    ): self {
        $payload = new self();
        $payload->status = 'pending';
        $payload->queryId = $queryId;
        $payload->subject = $subject;
        $payload->message = $message;

        return $payload;
    }

    /**
     * Factory method for error responses
     */
    public static function error(string $errorMessage): self
    {
        $payload = new self();
        $payload->ok = false;
        $payload->error = $errorMessage;

        return $payload;
    }

    public function jsonSerialize(): array
    {
        $result = [
            'ok' => $this->ok,
        ];

        if (! $this->ok) {
            $result['error'] = $this->error;

            return $result;
        }

        $result['status'] = $this->status;
        $result['query_id'] = $this->queryId;
        $result['subject'] = $this->subject;
        $result['message'] = $this->message;

        return $result;
    }
}
