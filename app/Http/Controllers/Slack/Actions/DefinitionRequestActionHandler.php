<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack\Actions;

use App\Infrastructure\Slack\DefinitionModalBuilder;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Query;
use App\Models\Tenant;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles definition request button actions
 */
class DefinitionRequestActionHandler
{
    public function __construct(
        private readonly SlackMessenger $slackMessenger,
        private readonly DefinitionModalBuilder $modalBuilder
    ) {
    }

    /**
     * Handle the definition request button click
     */
    public function handle(
        int $queryId,
        string $subject,
        string $triggerId,
        array $payload,
        Tenant $tenant
    ): Response {
        try {
            $this->validateQuery($queryId);

            $view = $this->modalBuilder->buildDefinitionModal($queryId, $subject);
            $success = $this->openModal($tenant, $triggerId, $view, $queryId, $subject);

            if ($success) {
                $this->updateOriginalMessage($tenant, $payload);
            }
        } catch (Exception $e) {
            Log::error('Error opening definition modal', [
                'query_id' => $queryId,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    }

    /**
     * Validate that the query exists
     */
    private function validateQuery(int $queryId): void
    {
        $query = Query::with('thread')->find($queryId);

        if (! $query) {
            throw new Exception("Query {$queryId} not found");
        }
    }

    /**
     * Open the modal
     */
    private function openModal(
        Tenant $tenant,
        string $triggerId,
        array $view,
        int $queryId,
        string $subject
    ): bool {
        $success = $this->slackMessenger->openModal($tenant, $triggerId, $view);

        if ($success) {
            Log::info('Definition modal opened successfully', [
                'query_id' => $queryId,
                'subject' => $subject,
                'trigger_id' => $triggerId,
            ]);
        } else {
            Log::warning('Failed to open definition modal', [
                'query_id' => $queryId,
                'subject' => $subject,
                'trigger_id' => $triggerId,
            ]);
        }

        return $success;
    }

    /**
     * Update the original message to show "getting your input"
     */
    private function updateOriginalMessage(Tenant $tenant, array $payload): void
    {
        $channel = $payload['channel']['id'] ?? null;
        $ts = $payload['message']['ts'] ?? ($payload['container']['message_ts'] ?? null);
        $blocks = $payload['message']['blocks'] ?? [];

        if (! $channel || ! $ts) {
            Log::warning('Missing channel or timestamp for message update', [
                'channel' => $channel,
                'ts' => $ts,
            ]);

            return;
        }

        // Remove the actions block (buttons)
        $blocks = array_values(array_filter($blocks, fn ($b) => ($b['type'] ?? '') !== 'actions'));

        // Add a small confirmation message
        $blocks[] = [
            'type' => 'context',
            'elements' => [[
                'type' => 'mrkdwn',
                'text' => '_getting your input_',
            ]],
        ];

        $this->slackMessenger->updateMessage($tenant, $channel, $ts, '_getting your input_', $blocks);
    }
}
