<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\EntityNotFoundException;
use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Jobs\GenerateSqlFromQueryJob;
use App\Jobs\Middleware\FailOnUnrecoverableException;
use App\Services\Llm\LlmProviderResolver;
use App\Services\Llm\PrismProviderMapper;
use App\Services\Llm\PromptBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UnrecoverableJobExceptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_fails_job_immediately_when_entity_not_found(): void
    {
        // Create a job with non-existent IDs
        $job = new GenerateSqlFromQueryJob(999, 888);

        // Mock the middleware to capture the failure
        $middleware = new FailOnUnrecoverableException();
        $jobFailed = false;
        $failedException = null;

        // Create a mock job that tracks failures
        $mockJob = new class($job)
        {
            private $originalJob;

            public $failed = false;

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
                $this->failed = true;
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

        // Assert that the job failed immediately
        $this->assertTrue($mockJob->failed);
        $this->assertInstanceOf(EntityNotFoundException::class, $mockJob->failedException);
        $this->assertStringContainsString('Thread', $mockJob->failedException->getMessage());
    }

    #[Test]
    public function it_logs_unrecoverable_exceptions(): void
    {
        // Create a job with non-existent IDs
        $job = new GenerateSqlFromQueryJob(999, 888);

        // Mock the middleware
        $middleware = new FailOnUnrecoverableException();

        // Create a mock job
        $mockJob = new class($job)
        {
            private $originalJob;

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
                // Job failed
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

        // Execute the middleware and capture logs
        Log::shouldReceive('error')
            ->twice() // Once from the job, once from the middleware
            ->withAnyArgs();

        $middleware->handle($mockJob, function ($job) {
            return $job->handle();
        });
    }
}
