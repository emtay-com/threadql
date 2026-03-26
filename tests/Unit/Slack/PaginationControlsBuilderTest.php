<?php

declare(strict_types=1);

namespace Tests\Unit\Slack;

use App\Infrastructure\Slack\PaginationControlsBuilder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PaginationControlsBuilderTest extends TestCase
{
    private PaginationControlsBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PaginationControlsBuilder();
    }

    #[Test]
    public function it_builds_controls_with_only_more_button_at_start(): void
    {
        $result = $this->builder->build(123, 0, 10, 25);

        $this->assertEquals('Total rows: 25, showing 1–10', $result['text']);
        $this->assertCount(2, $result['blocks']); // section + actions

        // Check section block
        $this->assertEquals('section', $result['blocks'][0]['type']);
        $this->assertStringContainsString('*Total rows:* 25', $result['blocks'][0]['text']['text']);
        $this->assertStringContainsString('*showing* 1–10', $result['blocks'][0]['text']['text']);

        // Check actions block
        $this->assertEquals('actions', $result['blocks'][1]['type']);
        $this->assertCount(1, $result['blocks'][1]['elements']); // Only "More results" button

        $button = $result['blocks'][1]['elements'][0];
        $this->assertEquals('More results »', $button['text']['text']);
        $this->assertEquals('query_pagination_next_123', $button['action_id']);
        $this->assertEquals(10, json_decode($button['value'], true));
    }

    #[Test]
    public function it_builds_controls_with_both_buttons_in_middle(): void
    {
        $result = $this->builder->build(123, 10, 10, 25);

        $this->assertEquals('Total rows: 25, showing 11–20', $result['text']);
        $this->assertCount(2, $result['blocks']); // section + actions

        // Check actions block has both buttons
        $this->assertEquals('actions', $result['blocks'][1]['type']);
        $this->assertCount(2, $result['blocks'][1]['elements']);

        $prevButton = $result['blocks'][1]['elements'][0];
        $nextButton = $result['blocks'][1]['elements'][1];

        $this->assertEquals('« Previous', $prevButton['text']['text']);
        $this->assertEquals('query_pagination_prev_123', $prevButton['action_id']);
        $this->assertEquals(0, json_decode($prevButton['value'], true));

        $this->assertEquals('More results »', $nextButton['text']['text']);
        $this->assertEquals('query_pagination_next_123', $nextButton['action_id']);
        $this->assertEquals(20, json_decode($nextButton['value'], true));
    }

    #[Test]
    public function it_builds_controls_with_only_previous_button_at_end(): void
    {
        $result = $this->builder->build(123, 20, 10, 25);

        $this->assertEquals('Total rows: 25, showing 21–25', $result['text']);
        $this->assertCount(2, $result['blocks']); // section + actions

        // Check actions block has only previous button
        $this->assertEquals('actions', $result['blocks'][1]['type']);
        $this->assertCount(1, $result['blocks'][1]['elements']);

        $button = $result['blocks'][1]['elements'][0];
        $this->assertEquals('« Previous', $button['text']['text']);
        $this->assertEquals('query_pagination_prev_123', $button['action_id']);
        $this->assertEquals(10, json_decode($button['value'], true));
    }

    #[Test]
    public function it_handles_edge_case_with_exactly_one_page(): void
    {
        $result = $this->builder->build(123, 0, 25, 25);

        $this->assertEquals('Total rows: 25, showing 1–25', $result['text']);
        $this->assertCount(1, $result['blocks']); // Only section, no actions (no buttons needed)
    }

    #[Test]
    public function it_handles_small_total_with_offset(): void
    {
        $result = $this->builder->build(123, 5, 10, 8);

        $this->assertEquals('Total rows: 8, showing 6–8', $result['text']);
        $this->assertCount(2, $result['blocks']); // section + actions

        // Should only have Previous button
        $this->assertEquals('actions', $result['blocks'][1]['type']);
        $this->assertCount(1, $result['blocks'][1]['elements']);

        $button = $result['blocks'][1]['elements'][0];
        $this->assertEquals('« Previous', $button['text']['text']);
        $this->assertEquals(0, json_decode($button['value'], true));
    }
}
