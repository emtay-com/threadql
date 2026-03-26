<?php

declare(strict_types=1);

namespace App\CommandHandlers\Slack;

use App\Command\CreateDefinitionCommand;
use App\Command\ParseDefinitionCommand;
use App\Command\Slack\DefineCommand;
use App\Command\Slack\DefineResponse;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Models\Thread;
use Illuminate\Support\Facades\Config;

/**
 * Handler for defining business terms via Slack
 */
class SlackDefineCommandHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly DomainCommandBus $commandBus,
    ) {
    }

    /**
     * Handle the define command
     */
    public function __invoke(DefineCommand $command): DefineResponse
    {
        $parsedResult = $this->parseDefinitionInput($command->input);
        $threadId = $this->resolveThreadId($command);
        $response = $this->createDefinition($command, $parsedResult, $threadId);

        return $this->buildResponse($response);
    }

    /**
     * Parse the definition input.
     */
    private function parseDefinitionInput(string $input): array
    {
        $parseCommand = new ParseDefinitionCommand($input);
        $parseResponse = $this->commandBus->dispatch($parseCommand);

        if (! $parseResponse->isSuccess()) {
            throw new \InvalidArgumentException($parseResponse->getErrors()[0]);
        }

        return $parseResponse->getResult();
    }

    /**
     * Resolve thread ID from command if thread_ts is provided.
     */
    private function resolveThreadId(DefineCommand $command): ?int
    {
        if (! $command->threadTs) {
            return null;
        }

        $thread = Thread::where('thread_ts', $command->threadTs)
            ->where('channel_id', $command->channelId)
            ->first();

        return $thread?->id;
    }

    /**
     * Create the definition using domain command.
     */
    private function createDefinition(DefineCommand $command, array $parsedResult, ?int $threadId): mixed
    {
        $createCommand = new CreateDefinitionCommand(
            tenantId: Config::get('slack.default_tenant_id'),
            userId: $command->userId,
            threadId: $threadId,
            subject: $parsedResult['subject'],
            definition: $parsedResult['definition'],
        );

        return $this->commandBus->dispatch($createCommand);
    }

    /**
     * Build the response based on creation result.
     */
    private function buildResponse(mixed $response): DefineResponse
    {
        if ($response->created) {
            return DefineResponse::success('Got it, thanks.');
        }

        return DefineResponse::success(
            'A definition for "'.$response->subject.'" already exists: '.$response->definition
        );
    }
}
