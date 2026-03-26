<?php

declare(strict_types=1);

namespace Tests\Feature\Prompt;

use App\Models\Datasource;
use App\Models\Tenant;
use App\Prompt\Views\FollowupPromptView;
use App\Prompt\Views\InitialPromptView;
use Tests\TestCase;

/**
 * Test that prompt views include the new date-related sections
 */
class IncludesDateSectionsTest extends TestCase
{
    /**
     * Test that InitialPromptView includes system definitions and date particles
     */
    public function test_initial_prompt_includes_date_sections(): void
    {
        // Create tenant and datasource with specific timezones
        $tenant = Tenant::factory()->create([
            'timezone' => 'Europe/Amsterdam',
        ]);
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'UTC',
        ]);

        // Create view with timezone data
        $data = [
            'tenant_timezone' => 'Europe/Amsterdam',
            'datasource_timezone' => 'UTC',
            'now_utc' => '2024-01-15T10:30:45+00:00',
            'start_of_week' => 'monday',
            'week_definition' => 'iso',
        ];
        $view = new InitialPromptView($data);

        $rendered = $view->renderSystem();

        // Assert system definitions are included
        $this->assertStringContainsString('System time (UTC now):', $rendered);
        $this->assertStringContainsString('Tenant timezone: Europe/Amsterdam', $rendered);
        $this->assertStringContainsString('Datasource timezone: UTC', $rendered);
        $this->assertStringContainsString('Start of week: monday', $rendered);
        $this->assertStringContainsString('Week definition: iso', $rendered);

        // Assert date particles are included
        $this->assertStringContainsString('Date ranges MUST be represented with two named parameters', $rendered);
        $this->assertStringContainsString('_start and *_end', $rendered);
        $this->assertStringContainsString('end-exclusive intervals: [start, end)', $rendered);
        $this->assertStringContainsString('TENANT timezone', $rendered);
        $this->assertStringContainsString('DATASOURCE timezone', $rendered);
    }

    /**
     * Test that FollowupPromptView includes date sections
     */
    public function test_followup_prompt_includes_date_sections(): void
    {
        // Create tenant and datasource with specific timezones
        $tenant = Tenant::factory()->create([
            'timezone' => 'America/New_York',
        ]);
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'America/Los_Angeles',
        ]);

        // Create view with timezone data
        $data = [
            'tenant_timezone' => 'America/New_York',
            'datasource_timezone' => 'America/Los_Angeles',
            'now_utc' => '2024-01-15T10:30:45+00:00',
            'start_of_week' => 'monday',
            'week_definition' => 'iso',
        ];
        $view = new FollowupPromptView($data);

        $rendered = $view->renderSystem();

        // Assert system definitions are included
        $this->assertStringContainsString('System time (UTC now):', $rendered);
        $this->assertStringContainsString('Tenant timezone: America/New_York', $rendered);
        $this->assertStringContainsString('Datasource timezone: America/Los_Angeles', $rendered);

        // Assert date particles are included
        $this->assertStringContainsString('Date ranges MUST be represented with two named parameters', $rendered);
        $this->assertStringContainsString('today_start / today_end', $rendered);
        $this->assertStringContainsString('yesterday_start / yesterday_end', $rendered);
    }

    /**
     * Test that prompt views use UTC defaults when no timezone data provided
     */
    public function test_prompt_views_use_utc_defaults(): void
    {
        // Create basic view without setting timezone data
        $view = new InitialPromptView();

        $rendered = $view->renderSystem();

        // Should use defaults from the Blade template fallbacks
        $this->assertStringContainsString('Tenant timezone: UTC', $rendered);
        $this->assertStringContainsString('Datasource timezone: UTC', $rendered);
    }

    /**
     * Test that system definitions include proper timestamp format
     */
    public function test_system_definitions_includes_proper_timestamp_format(): void
    {
        $view = new InitialPromptView();
        $view->setTimezoneData('UTC', 'UTC', '2024-01-15T10:30:45+00:00');

        $rendered = $view->renderSystem();

        // Should include the provided timestamp
        $this->assertStringContainsString('System time (UTC now): 2024-01-15T10:30:45+00:00', $rendered);
    }
}
