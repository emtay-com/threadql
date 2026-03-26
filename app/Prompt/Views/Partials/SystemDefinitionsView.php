<?php

declare(strict_types=1);

namespace App\Prompt\Views\Partials;

use App\Enums\SettingEnum;
use App\Models\Datasource;
use App\Models\GeneralSetting;
use App\Models\Tenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * View class for rendering system definitions partial
 */
class SystemDefinitionsView
{
    /**
     * Create a new SystemDefinitionsView instance.
     */
    public function __construct(
        private array $data = []
    ) {
    }

    /**
     * Render the system definitions partial.
     */
    public function render(): string
    {
        return view('prompts.partials.system_definitions', $this->data)->render();
    }

    /**
     * Create a SystemDefinitionsView from tenant and datasource models.
     */
    public static function fromTenantAndDatasource(
        Tenant $tenant,
        Datasource $datasource,
        ?CarbonInterface $now = null
    ): self {
        $now = $now ?? Carbon::now();

        return new self([
            'now_utc' => $now->utc()
                ->toIso8601String(),
            'tenant_timezone' => ! empty($tenant->timezone) ? $tenant->timezone : 'UTC',
            'datasource_timezone' => ! empty($datasource->timezone) ? $datasource->timezone : 'UTC',
            'start_of_week' => GeneralSetting::resolve(SettingEnum::START_OF_WEEK)->value,
            'week_definition' => GeneralSetting::resolve(SettingEnum::WEEK_DEFINITION)->value,
        ]);
    }

    /**
     * Set the data array.
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the data array.
     */
    public function getData(): array
    {
        return $this->data;
    }
}
