<?php

declare(strict_types=1);

namespace App\Http\Controllers\Slack;

use App\Enums\SlackSubcommand;
use App\Http\Controllers\Controller;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Slack\SlackMessenger;
use App\Models\Tenant;
use App\Slack\Commands\SlackCommandFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling Slack slash commands
 */
class SlackSlashController extends Controller
{
    private Tenant $tenant;

    public function __construct(
        private readonly DomainCommandBus $commandBus,
        private readonly SlackMessenger $slackMessenger,
        private readonly SlackCommandFactory $commandFactory,
    ) {
    }

    /**
     * Handle Slack slash command
     */
    public function handle(Request $request, Tenant $tenant): JsonResponse
    {
        $this->tenant = $tenant;

        $commandContext = $this->extractCommandContext($request);

        $this->logIncomingCommand($commandContext);

        $subcommand = $this->parseSubcommand($commandContext['text']);

        if (! $this->isValidSubcommand($subcommand)) {
            $this->sendInvalidSubcommandResponse($commandContext);

            return new JsonResponse(null, Response::HTTP_NO_CONTENT);
        }

        $message = $this->executeCommand($subcommand, $commandContext, $tenant);

        $this->sendSlackResponse(
            $commandContext['channelId'],
            $commandContext['threadTs'],
            $commandContext['userId'],
            $message
        );

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Extract command context from request
     *
     * @return array<string, mixed>
     */
    private function extractCommandContext(Request $request): array
    {
        return [
            'text' => $request->input('text', ''),
            'teamId' => $request->input('team_id'),
            'channelId' => $request->input('channel_id'),
            'userId' => $request->input('user_id'),
            'threadTs' => $request->input('thread_ts'),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logIncomingCommand(array $context): void
    {
        Log::info('Slack slash command received', [
            'text' => $context['text'],
            'team_id' => $context['teamId'],
            'channel_id' => $context['channelId'],
            'user_id' => $context['userId'],
            'thread_ts' => $context['threadTs'],
        ]);
    }

    /**
     * Parse subcommand from text
     */
    private function parseSubcommand(string $text): string
    {
        $parts = explode(' ', trim($text), 2);

        return strtolower($parts[0]);
    }

    /**
     * Check if subcommand is valid
     */
    private function isValidSubcommand(string $subcommand): bool
    {
        return ! empty($subcommand) && SlackSubcommand::isValid($subcommand);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function sendInvalidSubcommandResponse(array $context): void
    {
        $this->sendSlackResponse(
            $context['channelId'],
            $context['threadTs'],
            $context['userId'],
            'Unknown subcommand. Use: /soong help for a list of commands'
        );
    }

    /**
     * Execute command and return response message
     *
     * @param array<string, mixed> $context
     */
    private function executeCommand(string $subcommand, array $context, Tenant $tenant): string
    {
        try {
            $remainder = $this->getRemainder($context['text']);

            $command = $this->commandFactory->create(
                $subcommand,
                $remainder,
                $context['userId'],
                $context['threadTs'],
                $context['channelId'],
                $context['teamId'],
                $tenant
            );

            $response = $this->commandBus->dispatch($command);

            return $response->isSuccess()
                ? $response->getResult()
                : implode("\n", $response->getErrors());

        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Get remainder text after subcommand
     */
    private function getRemainder(string $text): string
    {
        $parts = explode(' ', trim($text), 2);

        return $parts[1] ?? '';
    }

    /**
     * Send response to Slack
     */
    private function sendSlackResponse(?string $channelId, ?string $threadTs, ?string $userId, string $message): void
    {
        try {
            // Guard against null values
            if (! $channelId || ! $userId) {
                Log::warning('Cannot send Slack response: missing channel_id or user_id', [
                    'channel_id' => $channelId,
                    'user_id' => $userId,
                ]);

                return;
            }

            if ($threadTs) {
                //dd($channelId, $threadTs, $message);

                $this->slackMessenger->replyInThread($this->tenant, $channelId, $threadTs, $message);
            } else {
                $this->slackMessenger->sendEphemeral($this->tenant, $channelId, $userId, $message);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Slack response', [
                'error' => $e->getMessage(),
                'channel_id' => $channelId,
                'thread_ts' => $threadTs,
                'user_id' => $userId,
            ]);
        }
    }
}
