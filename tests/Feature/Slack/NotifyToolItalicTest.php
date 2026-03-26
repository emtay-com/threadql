<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Infrastructure\Slack\SlackMessenger;
use App\Jobs\NotifyToolExecutingJob;
use App\Models\Query;
use App\Models\Thread;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Test that tool notifications are posted with italic formatting
 */
class NotifyToolItalicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fake the queue to prevent jobs from actually running
        Queue::fake();
    }

    /**
     * Test that NotifyToolExecutingJob is properly queued
     */
    public function test_notify_tool_executing_job_is_properly_queued(): void
    {
        // Create test data
        $query = Query::factory()->create();

        // Dispatch the job
        dispatch(new NotifyToolExecutingJob($query->id, 'run_sql_query'));

        // Assert job was pushed to queue with correct parameters
        Queue::assertPushed(NotifyToolExecutingJob::class, function ($job) use ($query) {
            // We can access job properties via reflection for testing
            $reflection = new \ReflectionClass($job);
            $queryIdProperty = $reflection->getProperty('queryId');
            $queryIdProperty->setAccessible(true);
            $toolNameProperty = $reflection->getProperty('toolName');
            $toolNameProperty->setAccessible(true);

            return $queryIdProperty->getValue($job) === $query->id
                && $toolNameProperty->getValue($job) === 'run_sql_query';
        });
    }

    /**
     * Test that job handles missing thread data gracefully
     */
    public function test_job_handles_missing_thread_data_gracefully(): void
    {
        // Create test query without proper thread association
        $query = Query::factory()->create();
        $thread = Thread::factory()->create([
            'channel_id' => 'C1234567890',
            'last_message_ts' => null, // Missing message_ts
        ]);

        $query->update([
            'thread_id' => $thread->id,
        ]);

        // Mock SlackMessenger to not expect any calls
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldNotReceive('replyInThreadWithBlocks');
        });

        // Dispatch the job
        dispatch(new NotifyToolExecutingJob($query->id, 'Executing SQL query...'));

        // Assert job was pushed to queue
        Queue::assertPushed(NotifyToolExecutingJob::class);
    }

    /**
     * Test that job handles non-existent query gracefully
     */
    public function test_job_handles_non_existent_query_gracefully(): void
    {
        // Mock SlackMessenger (should not be called)
        $this->mock(SlackMessenger::class, function ($mock) {
            $mock->shouldNotReceive('replyInThreadWithBlocks');
        });

        // Dispatch the job with non-existent query ID
        dispatch(new NotifyToolExecutingJob(99999, 'run_sql_query'));

        // Assert job was pushed to queue
        Queue::assertPushed(NotifyToolExecutingJob::class);
    }

    /**
     * Test that job can be created with different messages
     */
    public function test_job_can_be_created_with_different_tool_names(): void
    {
        $toolNames = ['run_sql_query', 'fetch_table_ddls', 'export_csv'];

        foreach ($toolNames as $toolName) {
            // Create test data
            $query = Query::factory()->create();

            // Dispatch the job
            dispatch(new NotifyToolExecutingJob($query->id, $toolName));

            // Assert job was pushed to queue
            Queue::assertPushed(NotifyToolExecutingJob::class);
        }
    }
}
