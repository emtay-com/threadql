<?php

declare(strict_types=1);

namespace App\CommandHandlers\Slack;

use App\Command\Slack\DebugToggleCommand;
use App\Command\Slack\DebugToggleResponse;
use App\Enums\Settings;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Slack\SlackUserResolver;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Models\Tenant;

/**
 * Handler for toggling debug settings via Slack
 */
class SlackDebugToggleCommandHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly SlackUserResolver $userResolver,
        private readonly SlackUserSettingService $settingService,
    ) {
    }

    /**
     * Handle the debug toggle command
     */
    public function __invoke(DebugToggleCommand $command): DebugToggleResponse
    {
        // Validate toggle value
        if (! in_array($command->toggle, ['on', 'off'], true)) {
            return DebugToggleResponse::error('Try "/soong debug on" or "/soong debug off"');
        }

        // Resolve tenant
        $tenant = Tenant::find($command->tenantId);
        if (! $tenant) {
            return DebugToggleResponse::error('Unable to find tenant configuration');
        }

        // Find or create SlackUser
        $slackUser = $this->userResolver->findOrCreate($tenant, $command->slackUserId);

        // Set the debug preference
        $isEnabled = $command->toggle === 'on';
        $this->settingService->setEnabled($slackUser, Settings::DEBUG, $isEnabled);

        // Return success message
        $status = $isEnabled ? 'ON' : 'OFF';

        return DebugToggleResponse::success("_Debug mode is now **{$status}** for you in this workspace._");
    }
}
