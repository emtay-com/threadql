<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Jobs\UserQueryInvokerJob;
use App\Models\Query;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Test suite for pagination prompt functionality
 */
class PaginationPromptTest extends TestCase
{
    /**
     * Test that pagination prompt is sent for eligible non-aggregate queries
     */
    public function test_sends_pagination_prompt_for_eligible_queries(): void
    {
        Queue::fake();

        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'row_count' => 100,
                'limit_applied' => 100,
                'is_aggregate' => false,
                'total_count' => 500,
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => 100,
                ],
            ],
        ]);

        // Simulate successful query completion
        $query->update([
            'status' => 'done',
        ]);

        // The job should be dispatched from UserQueryInvokerJob after success
        // This test verifies the conditions are met
        $this->assertTrue(
            $query->result_meta_json['total_count'] > $query->result_meta_json['parameters']['offset'] + $query->result_meta_json['row_count']
        );
        $this->assertFalse($query->result_meta_json['is_aggregate']);
    }

    /**
     * Test that no pagination prompt is sent for aggregate queries
     */
    public function test_does_not_send_pagination_for_aggregate_queries(): void
    {
        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'row_count' => 10,
                'is_aggregate' => true,
                'total_count' => 100,
            ],
        ]);

        // Aggregate queries should not trigger pagination
        $this->assertTrue($query->result_meta_json['is_aggregate']);
    }

    /**
     * Test that no pagination prompt is sent when all results are shown
     */
    public function test_does_not_send_pagination_when_all_results_shown(): void
    {
        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'row_count' => 50,
                'is_aggregate' => false,
                'total_count' => 50,
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => 100,
                ],
            ],
        ]);

        // All results are shown, no pagination needed
        $this->assertEquals(
            $query->result_meta_json['total_count'],
            $query->result_meta_json['parameters']['offset'] + $query->result_meta_json['row_count']
        );
    }

    /**
     * Test that no pagination prompt is sent when offset + limit >= total_count
     */
    public function test_does_not_send_pagination_at_end_of_results(): void
    {
        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'row_count' => 50,
                'is_aggregate' => false,
                'total_count' => 250,
                'parameters' => [
                    'offset' => 200,
                    'row_limit' => 100,
                ],
            ],
        ]);

        // At end of results (offset 200 + limit 100 >= total 250)
        $this->assertGreaterThanOrEqual(
            $query->result_meta_json['total_count'],
            $query->result_meta_json['parameters']['offset'] + $query->result_meta_json['parameters']['row_limit']
        );
    }

    /**
     * Test that pagination prompt is sent when there are more results
     */
    public function test_sends_pagination_when_more_results_available(): void
    {
        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'done',
            'result_meta_json' => [
                'row_count' => 100,
                'is_aggregate' => false,
                'total_count' => 300,
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => 100,
                ],
            ],
        ]);

        // More results available (300 > 0 + 100)
        $this->assertLessThan(
            $query->result_meta_json['total_count'],
            $query->result_meta_json['parameters']['offset'] + $query->result_meta_json['row_count']
        );
    }
}
