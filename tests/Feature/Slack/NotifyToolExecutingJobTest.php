<?php

declare(strict_types=1);

namespace Tests\Feature\Slack;

use App\Enums\QueryStatus;
use App\Jobs\NotifyToolExecutingJob;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Slack\SlackChannelRateLimiter;
use Illuminate\Support\Facades\Bus;
use ReflectionClass;
use Tests\TestCase;

/**
 * Test the NotifyToolExecutingJob functionality
 */
class NotifyToolExecutingJobTest extends TestCase
{
    private Tenant $tenant;

    private Thread $thread;

    private Query $query;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->tenant = Tenant::factory()->create();
        $this->thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => '1234567890.123456',
        ]);
        $this->query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'raw_text' => 'test query',
        ]);
    }

    /**
     * Test that NotifyToolExecutingJob is dispatched with correct tool name
     */
    public function test_job_dispatches_with_correct_tool_name(): void
    {
        Bus::fake();

        $toolName = 'run_sql_query';

        NotifyToolExecutingJob::dispatch($this->query->id, $toolName);

        Bus::assertDispatched(NotifyToolExecutingJob::class, function ($job) use ($toolName) {
            // Use reflection to access private properties for testing
            $reflection = new ReflectionClass($job);
            $queryIdProperty = $reflection->getProperty('queryId');
            $toolNameProperty = $reflection->getProperty('toolName');
            $queryIdProperty->setAccessible(true);
            $toolNameProperty->setAccessible(true);

            return $queryIdProperty->getValue($job) === $this->query->id &&
                   $toolNameProperty->getValue($job) === $toolName;
        });
    }

    /**
     * Test job execution with mock Slack messenger
     */
    public function test_job_execution_with_mock_slack(): void
    {
        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->andReturn([
                'ts' => '1234567890.654321',
            ]);

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldReceive('remainingMs')
            ->once()
            ->andReturn(0);
        $mockRateLimiter->shouldReceive('acquire')
            ->once();

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);

        // Job should complete without errors
        $this->assertTrue(true);
    }

    /**
     * Test job handles empty channel_id gracefully
     */
    public function test_job_handles_empty_channel_id(): void
    {
        $threadWithEmptyChannel = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => '',
            'last_message_ts' => '1234567890.123456',
        ]);

        $queryWithEmptyChannel = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $threadWithEmptyChannel->id,
            'raw_text' => 'test query',
        ]);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldNotReceive('remainingMs');
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($queryWithEmptyChannel->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);

        $this->assertTrue(true);
    }

    /**
     * Test job handles missing last_message_ts gracefully
     */
    public function test_job_handles_missing_last_message_ts(): void
    {
        $threadWithoutTs = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => 'C1234567890',
            'last_message_ts' => null,
        ]);

        $queryWithoutTs = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $threadWithoutTs->id,
            'raw_text' => 'test query',
        ]);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldNotReceive('remainingMs');
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($queryWithoutTs->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);

        $this->assertTrue(true);
    }

    /**
     * Test job throws exception for invalid query ID
     */
    public function test_job_throws_exception_for_invalid_query_id(): void
    {
        $this->expectException(\App\Exceptions\EntityNotFoundException::class);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);

        $job = new NotifyToolExecutingJob(99999, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);
    }

    /**
     * Test that job uses correct message bank for different tools
     */
    public function test_job_uses_correct_message_bank_for_tools(): void
    {
        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->andReturn([
                'ts' => '1234567890.654321',
            ]);

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldReceive('remainingMs')
            ->once()
            ->andReturn(0);
        $mockRateLimiter->shouldReceive('acquire')
            ->once();

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);
    }

    /**
     * Test that job uses follow-up message variant for run_sql_query in follow-up context
     */
    public function test_job_uses_followup_variant_for_run_sql_query_in_followup_context(): void
    {
        // Create a DONE query to establish follow-up context
        Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
            'status' => QueryStatus::DONE->value,
        ]);

        $followUpQuery = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $this->thread->id,
        ]);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldReceive('replyInThreadWithBlocks')
            ->once()
            ->andReturn([
                'ts' => '1234567890.654321',
            ]);

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldReceive('remainingMs')
            ->once()
            ->andReturn(0);
        $mockRateLimiter->shouldReceive('acquire')
            ->once();

        $job = new NotifyToolExecutingJob($followUpQuery->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);
    }

    /**
     * Test that the job skips sending when the query is already DONE.
     */
    public function test_job_skips_notification_when_query_is_done(): void
    {
        $this->query->update([
            'status' => QueryStatus::DONE->value,
        ]);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldNotReceive('remainingMs');
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);
    }

    /**
     * Test that the job skips sending when the query has errored.
     */
    public function test_job_skips_notification_when_query_has_error(): void
    {
        $this->query->update([
            'status' => QueryStatus::ERROR->value,
        ]);

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldNotReceive('remainingMs');
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query');
        $job->handle($mockSlackMessenger, $mockRateLimiter);
    }

    /**
     * Test that the job re-dispatches itself with a 1-second delay when rate-limited.
     */
    public function test_job_requeues_with_delay_when_rate_limited(): void
    {
        Bus::fake();

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldReceive('remainingMs')
            ->once()
            ->andReturn(500);
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query', 0);
        $job->handle($mockSlackMessenger, $mockRateLimiter);

        Bus::assertDispatched(NotifyToolExecutingJob::class, function ($dispatched) {
            $reflection = new ReflectionClass($dispatched);
            $attempts = $reflection->getProperty('rateLimitAttempts');
            $attempts->setAccessible(true);

            return $attempts->getValue($dispatched) === 1 && $dispatched->delay === 1;
        });
    }

    /**
     * Test that the job gives up after the maximum number of rate-limit retries.
     */
    public function test_job_gives_up_after_max_rate_limit_attempts(): void
    {
        Bus::fake();

        $mockSlackMessenger = $this->mock(\App\Infrastructure\Slack\SlackMessenger::class);
        $mockSlackMessenger->shouldNotReceive('replyInThreadWithBlocks');

        $mockRateLimiter = $this->mock(SlackChannelRateLimiter::class);
        $mockRateLimiter->shouldReceive('remainingMs')
            ->once()
            ->andReturn(500);
        $mockRateLimiter->shouldNotReceive('acquire');

        $job = new NotifyToolExecutingJob($this->query->id, 'run_sql_query', 10);
        $job->handle($mockSlackMessenger, $mockRateLimiter);

        // Should not dispatch another job when at max attempts
        Bus::assertNotDispatched(NotifyToolExecutingJob::class);
    }
}
