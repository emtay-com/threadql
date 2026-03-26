<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Jobs\RequestDefinitionJob;
use App\Mcp\ToolResults\RequestDefinitionPayload;
use App\Models\Query;
use App\Models\ToolCall;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP Tool for requesting business definitions from users
 */
class RequestDefinitionTool extends Tool
{
    protected string $name = 'request_definition';

    protected string $description = 'Request a definition for a business concept from the user';

    /**
     * Get the tool's input schema.
     *
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        /** @var JsonSchema $queryId */
        $queryId = $schema->integer()
            ->description('The query ID');
        /** @var JsonSchema $subject */
        $subject = $schema->string()
            ->description('The business concept or term to request a definition for');

        return [
            'query_id' => $queryId,
            'subject' => $subject,
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $toolCall = null;
        $queryId = (int) $request->get('query_id');
        $subject = (string) $request->get('subject', '');

        try {
            // Validate inputs
            if ($queryId <= 0) {
                $payload = RequestDefinitionPayload::error('Invalid query_id provided');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            if (trim($subject) === '') {
                $payload = RequestDefinitionPayload::error('Invalid subject provided');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            // Find the query and validate it belongs to a thread and tenant
            $query = Query::with(['thread', 'tenant'])->find($queryId);
            if (! $query) {
                $payload = RequestDefinitionPayload::error('Query not found');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            if (! $query->thread || ! $query->tenant) {
                $payload = RequestDefinitionPayload::error('Query is missing required relationships');

                return Response::text(json_encode($payload->jsonSerialize()));
            }

            // Normalize subject: trim whitespace
            $normalizedSubject = trim($subject);

            // Create tool call record for logging
            $toolCall = ToolCall::create([
                'tenant_id' => $query->tenant_id,
                'query_id' => $queryId,
                'tool' => 'request_definition',
                'request_payload' => json_encode([
                    'query_id' => $queryId,
                    'subject' => $normalizedSubject,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]);

            // Dispatch background job to request definition
            RequestDefinitionJob::dispatch($queryId, $normalizedSubject);

            // Create success payload
            $payload = RequestDefinitionPayload::pending(
                $queryId,
                $normalizedSubject,
                'Definition requested; awaiting user input.'
            );

            // Update tool call with response
            if ($toolCall) {
                $toolCall->update([
                    'response_payload' => json_encode(
                        $payload->jsonSerialize(),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            return Response::text(json_encode($payload->jsonSerialize()));

        } catch (Exception $e) {
            Log::error('Error requesting definition', [
                'query_id' => $queryId,
                'subject' => $subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Create error payload
            $payload = RequestDefinitionPayload::error('Database error: '.$e->getMessage());

            // Update tool call with error response
            if ($toolCall) {
                $toolCall->update([
                    'response_payload' => json_encode(
                        $payload->jsonSerialize(),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            return Response::text(json_encode($payload->jsonSerialize()));
        }
    }
}
