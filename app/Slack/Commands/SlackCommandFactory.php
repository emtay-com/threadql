<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Models\Tenant;
use InvalidArgumentException;

/**
 * Factory for creating Slack command instances using strategy pattern.
 * Delegates command creation to specific command creator strategies.
 */
class SlackCommandFactory
{
    /**
     * @var array<string, CommandCreatorInterface>
     */
    private array $creators = [];

    /**
     * Register command creators
     */
    public function __construct()
    {
        $this->registerDefaultCreators();
    }

    /**
     * Register default command creators for all subcommands
     */
    private function registerDefaultCreators(): void
    {
        $this->register('define', new DefineCommandCreator);
        $this->register('help', new ShowHelpCommandCreator);
        $this->register('list', new ListCommandCreator);
        $this->register('survey', new SurveyToggleCommandCreator);
        $this->register('debug', new DebugToggleCommandCreator);
    }

    /**
     * Register a command creator for a specific subcommand
     */
    public function register(string $subcommand, CommandCreatorInterface $creator): void
    {
        $this->creators[$subcommand] = $creator;
    }

    /**
     * Create a command instance for the given subcommand
     *
     * @param string $subcommand The subcommand name
     * @param string $remainder The remainder text after the subcommand
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp
     * @param string $channelId The Slack channel ID
     * @param string|null $teamId The Slack team ID
     * @param Tenant $tenant The tenant context
     * @return object The created command instance
     */
    public function create(
        string $subcommand,
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): object {
        if (! isset($this->creators[$subcommand])) {
            throw new InvalidArgumentException("Unknown subcommand: {$subcommand}");
        }

        $creator = $this->creators[$subcommand];

        return $creator->create($remainder, $userId, $threadTs, $channelId, $teamId, $tenant);
    }

    /**
     * Check if a subcommand is registered
     */
    public function hasCreator(string $subcommand): bool
    {
        return isset($this->creators[$subcommand]);
    }

    /**
     * Get all registered subcommands
     *
     * @return array<string>
     */
    public function getRegisteredSubcommands(): array
    {
        return array_keys($this->creators);
    }
}
