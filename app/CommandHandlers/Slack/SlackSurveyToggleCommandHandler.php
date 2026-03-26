<?php

declare(strict_types=1);

namespace App\CommandHandlers\Slack;

use App\Command\Slack\SurveyToggleCommand;
use App\Command\Slack\SurveyToggleResponse;
use App\Enums\Settings;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Infrastructure\Slack\SlackUserResolver;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Models\Tenant;

/**
 * Handler for toggling survey settings via Slack
 */
class SlackSurveyToggleCommandHandler implements DomainCommandHandler
{
    public function __construct(
        private readonly SlackUserResolver $userResolver,
        private readonly SlackUserSettingService $settingService,
    ) {
    }

    /**
     * Handle the survey toggle command
     */
    public function __invoke(SurveyToggleCommand $command): SurveyToggleResponse
    {
        // Validate toggle value
        if (! in_array($command->toggle, ['on', 'off'], true)) {
            return SurveyToggleResponse::error('Try "/soong survey on" or "/soong survey off"');
        }

        // Resolve tenant
        $tenant = Tenant::find($command->tenantId);
        if (! $tenant) {
            return SurveyToggleResponse::error('Unable to find tenant configuration');
        }

        // Find or create SlackUser
        $slackUser = $this->userResolver->findOrCreate($tenant, $command->slackUserId);

        // Set the survey preference
        $isEnabled = $command->toggle === 'on';
        $this->settingService->setEnabled($slackUser, Settings::SURVEYS, $isEnabled);

        // Return success message
        $status = $isEnabled ? 'ON' : 'OFF';

        return SurveyToggleResponse::success("_Surveys are now **{$status}** for you in this workspace._");
    }
}
