<?php

declare(strict_types=1);

namespace Tests\Unit\PromptViews;

use App\Models\Datasource;
use App\Models\Tenant;
use App\Prompt\Views\Partials\SystemDefinitionsView;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Test the SystemDefinitionsView functionality
 */
class SystemDefinitionsViewTest extends TestCase
{
    /**
     * Test that SystemDefinitionsView renders with correct timezone data
     */
    public function test_renders_with_tenant_and_datasource_timezones(): void
    {
        // Create tenant and datasource with specific timezones
        $tenant = Tenant::factory()->create([
            'timezone' => 'Europe/Amsterdam',
        ]);
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'UTC',
        ]);

        // Create view with fixed time
        $fixedTime = Carbon::create(2024, 1, 15, 10, 30, 45, 'UTC');
        $view = SystemDefinitionsView::fromTenantAndDatasource($tenant, $datasource, $fixedTime);

        $rendered = $view->render();

        // Assert the rendered content contains expected values
        $this->assertStringContainsString('System time (UTC now): 2024-01-15T10:30:45+00:00', $rendered);
        $this->assertStringContainsString('Tenant timezone: Europe/Amsterdam', $rendered);
        $this->assertStringContainsString('Datasource timezone: UTC', $rendered);
        $this->assertStringContainsString('Start of week: monday', $rendered);
        $this->assertStringContainsString('Week definition: iso', $rendered);
    }

    /**
     * Test that SystemDefinitionsView uses UTC defaults when timezones are null
     */
    public function test_uses_utc_defaults_when_timezones_null(): void
    {
        // Create tenant and datasource with empty timezone values (should use defaults)
        $tenant = Tenant::factory()->create([
            'timezone' => '',
        ]);
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => '',
        ]);

        $view = SystemDefinitionsView::fromTenantAndDatasource($tenant, $datasource);

        $rendered = $view->render();

        // Assert defaults are used
        $this->assertStringContainsString('Tenant timezone: UTC', $rendered);
        $this->assertStringContainsString('Datasource timezone: UTC', $rendered);
    }

    /**
     * Test that SystemDefinitionsView handles current time when not provided
     */
    public function test_handles_current_time_when_not_provided(): void
    {
        $tenant = Tenant::factory()->create([
            'timezone' => 'America/New_York',
        ]);
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'America/Los_Angeles',
        ]);

        // Don't pass time, should use current time
        $view = SystemDefinitionsView::fromTenantAndDatasource($tenant, $datasource);

        $rendered = $view->render();

        // Should contain a valid ISO8601 timestamp
        $this->assertMatchesRegularExpression(
            '/System time \(UTC now\): \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+\d{2}:\d{2}/',
            $rendered
        );
        $this->assertStringContainsString('Tenant timezone: America/New_York', $rendered);
        $this->assertStringContainsString('Datasource timezone: America/Los_Angeles', $rendered);
    }
}
