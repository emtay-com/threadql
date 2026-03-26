<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\SendNoResultsMessageJob;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use Mockery;
use Tests\TestCase;

/**
 * Test the SendNoResultsMessageJob functionality
 */
class SendNoResultsMessageJobTest extends TestCase
{
    public function test_job_sends_no_results_message(): void
    {
        // Create test data
        $tenant = Tenant::factory()->create();
        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
            'channel_id' => 'C1234567890',
            'thread_ts' => '1234567890.123456',
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'thread_id' => $thread->id,
        ]);

        // Mock SlackMessenger
        $mockSlackMessenger = Mockery::mock(SlackMessenger::class);
        $mockSlackMessenger->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->andReturn([
                'ts' => '1234567890.123457',
            ]);

        // Create and execute the job
        $job = new SendNoResultsMessageJob($query->id);
        $job->handle($mockSlackMessenger);

        // Verify the queryId getter works
        $this->assertEquals($query->id, $job->getQueryId());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
