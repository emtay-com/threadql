<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Jobs\GenerateSqlFromQueryJob;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Models\Query;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Llm\LlmProviderResolver;
use App\Services\Llm\PrismProviderMapper;
use App\Services\Llm\PromptBuilder;
use Exception;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobRetryBehaviorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_does_not_retry_when_entity_not_found(): void
    {
        // Create a job with non-existent IDs
        $job = new GenerateSqlFromQueryJob(999, 888);

        // Mock the middleware
        $middleware = new FailOnUnrecoverableException();

        // Create a mock job that tracks attempts
        $mockJob = new class($job)
        {
            private $originalJob;

            public $attempts = 0;

            public $failed = false;

            public function __construct($originalJob)
            {
                $this->originalJob = $originalJob;
            }

            public function getJobId(): string
            {
                return 'test-job-id';
            }

            public function attempts(): int
            {
                return $this->attempts;
            }

            public function fail($exception): void
            {
                $this->failed = true;
            }

            public function handle(): void
            {
                $this->attempts++;
                $this->originalJob->handle(
                    app(LlmProviderResolver::class),
                    app(PrismProviderMapper::class),
                    app(PromptBuilder::class),
                    app(SlackMessageDispatcher::class)
                );
            }
        };

        // Execute the middleware
        $middleware->handle($mockJob, function ($job) {
            return $job->handle();
        });

        // Assert that the job failed immediately and only attempted once
        $this->assertTrue($mockJob->failed);
        $this->assertEquals(1, $mockJob->attempts);
    }

    #[Test]
    public function it_retries_when_recoverable_exception_occurs(): void
    {
        // Create a job with valid IDs but mock services to throw recoverable exceptions
        $thread = Thread::factory()->create();
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
        ]);
        $tenant = Tenant::factory()->create();

        $job = new GenerateSqlFromQueryJob($thread->id, $query->id);

        // Mock the middleware
        $middleware = new FailOnUnrecoverableException();

        // Create a mock job that tracks attempts
        $mockJob = new class($job)
        {
            private $originalJob;

            public $attempts = 0;

            public $failed = false;

            public function __construct($originalJob)
            {
                $this->originalJob = $originalJob;
            }

            public function getJobId(): string
            {
                return 'test-job-id';
            }

            public function attempts(): int
            {
                return $this->attempts;
            }

            public function fail($exception): void
            {
                $this->failed = true;
            }

            public function handle(): void
            {
                $this->attempts++;
                // Simulate a recoverable exception (like network timeout)
                throw new Exception('Network timeout - this should retry');
            }
        };

        // We expect this to throw an exception but not fail the job
        $this->expectException(Exception::class);

        // Execute the middleware
        $middleware->handle($mockJob, function ($job) {
            return $job->handle();
        });

        // Assert that the job did NOT fail immediately (should retry)
        $this->assertFalse($mockJob->failed);
        $this->assertEquals(1, $mockJob->attempts);
    }

    #[Test]
    public function it_provides_clear_error_messages_for_missing_entities(): void
    {
        // Create a job with non-existent IDs
        $job = new GenerateSqlFromQueryJob(999, 888);

        // Mock the middleware
        $middleware = new FailOnUnrecoverableException();

        // Create a mock job
        $mockJob = new class($job)
        {
            private $originalJob;

            public $failedException = null;

            public function __construct($originalJob)
            {
                $this->originalJob = $originalJob;
            }

            public function getJobId(): string
            {
                return 'test-job-id';
            }

            public function fail($exception): void
            {
                $this->failedException = $exception;
            }

            public function handle(): void
            {
                $this->originalJob->handle(
                    app(LlmProviderResolver::class),
                    app(PrismProviderMapper::class),
                    app(PromptBuilder::class),
                    app(SlackMessageDispatcher::class)
                );
            }
        };

        // Execute the middleware
        $middleware->handle($mockJob, function ($job) {
            return $job->handle();
        });

        // Assert that we get a clear error message
        $this->assertInstanceOf(EntityNotFoundException::class, $mockJob->failedException);
        $this->assertStringContainsString(
            "Entity of type 'Thread' with identifier '999' not found",
            $mockJob->failedException->getMessage()
        );
    }
}
