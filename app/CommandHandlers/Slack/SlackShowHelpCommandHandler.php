<?php

declare(strict_types=1);

namespace App\CommandHandlers\Slack;

use App\Command\Slack\ShowHelpCommand;
use App\Command\Slack\ShowHelpResponse;
use App\Infrastructure\Command\DomainCommandHandler;

/**
 * Handler for showing help information
 */
class SlackShowHelpCommandHandler implements DomainCommandHandler
{
    /**
     * Handle the show help command
     */
    public function __invoke(ShowHelpCommand $command): ShowHelpResponse
    {
        $cmd = $command->slashCommand;
        $helpText = "Available commands:\n".
            "• {$cmd} define <subject> = <definition>\n".
            "• {$cmd} define <subject> is a <definition>\n".
            "• {$cmd} define <subject> is <definition>\n".
            "• {$cmd} list definitions - Show all business definitions\n".
            "• {$cmd} list tables - Show all known tables\n".
            "• {$cmd} help - Show this help message";

        return ShowHelpResponse::success($helpText);
    }
}
