<?php

declare(strict_types=1);

namespace App\Prompt\Views;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use Carbon\Carbon;

/**
 * Prompt view for initial (first-turn) queries
 */
class InitialPromptView extends BasePromptView
{
    /**
     * Get the system prompt view name
     */
    protected function viewName(): string
    {
        return 'prompts.system_basic';
    }

    /**
     * Get the user message view name
     */
    protected function userViewName(): ?string
    {
        return 'prompts.user_initial';
    }

    /**
     * Set the query ID for the initial prompt
     */
    public function setQueryId(int $queryId): static
    {
        $this->data['query_id'] = $queryId;

        return $this;
    }

    /**
     * Set the user query text (cleaned of Slack mentions)
     */
    public function setUserQueryText(string $text): static
    {
        $this->data['user_query_text'] = $text;

        return $this;
    }

    /**
     * Set timezone data for system definitions
     */
    public function setTimezoneData(string $tenantTimezone, string $datasourceTimezone, ?string $nowUtc = null): static
    {
        $this->data['tenant_timezone'] = $tenantTimezone;
        $this->data['datasource_timezone'] = $datasourceTimezone;
        $this->data['now_utc'] = $nowUtc ?? Carbon::now()->utc()->toIso8601String();
        $this->data['start_of_week'] = GeneralSetting::resolve(SettingEnum::START_OF_WEEK)->value;
        $this->data['week_definition'] = GeneralSetting::resolve(SettingEnum::WEEK_DEFINITION)->value;

        return $this;
    }
}
