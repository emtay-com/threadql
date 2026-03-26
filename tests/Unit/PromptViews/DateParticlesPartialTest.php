<?php

declare(strict_types=1);

namespace Tests\Unit\PromptViews;

use Tests\TestCase;

/**
 * Test the date_particles.blade.php partial
 */
class DateParticlesPartialTest extends TestCase
{
    /**
     * Test that date_particles partial renders with correct timezone guidance
     */
    public function test_renders_date_particles_protocol_correctly(): void
    {
        $data = [
            'tenant_timezone' => 'Europe/Amsterdam',
            'datasource_timezone' => 'UTC',
            'ts_column' => 'created_at',
        ];

        $rendered = view('prompts.partials.date_particles', $data)
            ->render();

        // Assert key protocol elements are present
        $this->assertStringContainsString('_start and *_end', $rendered);
        $this->assertStringContainsString('end-exclusive intervals: [start, end)', $rendered);
        $this->assertStringContainsString('WHERE created_at >= :today_start', $rendered);
        $this->assertStringContainsString('Europe/Amsterdam', $rendered);
        $this->assertStringContainsString('UTC', $rendered);
        $this->assertStringContainsString('Y-m-d H:i:s', $rendered);
        $this->assertStringContainsString('today_start / today_end', $rendered);
        $this->assertStringContainsString('yesterday_start / yesterday_end', $rendered);
        $this->assertStringContainsString('last_week_start / last_week_end', $rendered);
        $this->assertStringContainsString('request_definition tool', $rendered);
        $this->assertStringContainsString('Validation rules', $rendered);
        $this->assertStringContainsString('NEVER inline user-provided literal dates', $rendered);
    }

    /**
     * Test that date_particles partial handles null ts_column
     */
    public function test_handles_null_timestamp_column(): void
    {
        $data = [
            'tenant_timezone' => 'America/New_York',
            'datasource_timezone' => 'America/Los_Angeles',
            'ts_column' => null,
        ];

        $rendered = view('prompts.partials.date_particles', $data)
            ->render();

        // Should contain placeholder when ts_column is null
        $this->assertStringContainsString('<timestamp_column>', $rendered);
        $this->assertStringContainsString('America/New_York', $rendered);
        $this->assertStringContainsString('America/Los_Angeles', $rendered);
    }

    /**
     * Test that date_particles partial includes all required examples
     */
    public function test_includes_all_required_date_examples(): void
    {
        $data = [
            'tenant_timezone' => 'UTC',
            'datasource_timezone' => 'UTC',
        ];

        $rendered = view('prompts.partials.date_particles', $data)
            ->render();

        // Check for all required date range examples
        $examples = [
            'today_start / today_end',
            'yesterday_start / yesterday_end',
            'last_week_start / last_week_end',
            'last_week_wed_start / last_week_wed_end',
            'this_month_start / this_month_end',
            'last_month_start / last_month_end',
            'April 14',
        ];

        foreach ($examples as $example) {
            $this->assertStringContainsString($example, $rendered, "Missing example: {$example}");
        }
    }

    /**
     * Test that date_particles partial includes timezone conversion guidance
     */
    public function test_includes_timezone_conversion_guidance(): void
    {
        $data = [
            'tenant_timezone' => 'Asia/Tokyo',
            'datasource_timezone' => 'Europe/London',
        ];

        $rendered = view('prompts.partials.date_particles', $data)
            ->render();

        $this->assertStringContainsString('Interpret relative phrases', $rendered);
        $this->assertStringContainsString('TENANT timezone', $rendered);
        $this->assertStringContainsString('DATASOURCE timezone', $rendered);
        $this->assertStringContainsString('Asia/Tokyo', $rendered);
        $this->assertStringContainsString('Europe/London', $rendered);
        $this->assertStringContainsString('Convert the resulting start/end', $rendered);
        $this->assertStringContainsString('DATASOURCE timezone', $rendered);
    }
}
