<?php

declare(strict_types=1);

namespace App\Slack\Commands;

use App\Command\Slack\SurveyToggleCommand;
use App\Infrastructure\Command\DomainCommand;
use App\Models\Tenant;
use InvalidArgumentException;

/**
 * Creates SurveyToggleCommand instances for the /survey subcommand.
 */
class SurveyToggleCommandCreator implements CommandCreatorInterface
{
    /**
     * Create a SurveyToggleCommand instance with validation.
     *
     * @param string $remainder The toggle value ("on" or "off")
     * @param string $userId The Slack user ID
     * @param string|null $threadTs The thread timestamp (unused)
     * @param string $channelId The Slack channel ID (unused)
     * @param string|null $teamId The Slack team ID
     * @param Tenant $tenant The tenant context
     * @return SurveyToggleCommand
     */
    public function create(
        string $remainder,
        string $userId,
        ?string $threadTs,
        string $channelId,
        ?string $teamId,
        Tenant $tenant
    ): DomainCommand {
        $toggle = trim(strtolower($remainder));

        if (empty($toggle)) {
            throw new InvalidArgumentException('Try "/soong survey on" or "/soong survey off"');
        }

        if (! in_array($toggle, ['on', 'off'], true)) {
            throw new InvalidArgumentException('Try "/soong survey on" or "/soong survey off"');
        }

        if (! $teamId) {
            throw new InvalidArgumentException('Unable to determine Slack workspace');
        }

        return new SurveyToggleCommand(
            slackTeamId: $teamId,
            slackUserId: $userId,
            tenantId: $tenant->id,
            toggle: $toggle
        );
    }
}
