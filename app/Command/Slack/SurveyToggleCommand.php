<?php

declare(strict_types=1);

namespace App\Command\Slack;

use App\Infrastructure\Command\DomainCommand;

/**
 * Command to toggle survey settings for a Slack user
 */
class SurveyToggleCommand implements DomainCommand
{
    public function __construct(
        public readonly string $slackTeamId,
        public readonly string $slackUserId,
        public readonly int $tenantId,
        public readonly string $toggle, // 'on' or 'off'
    ) {
    }
}
