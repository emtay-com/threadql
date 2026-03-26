<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Exceptions\UnrecoverableJobException;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

class FailOnUnrecoverableException
{
    /**
     * Handle the job middleware.
     *
     * @param mixed $job The job instance
     * @param Closure $next The next middleware in the chain
     * @return mixed
     */
    public function handle($job, Closure $next)
    {
        try {
            // Execute the job
            return $next($job);
        } catch (UnrecoverableJobException $e) {
            // Log the unrecoverable error
            Log::error('Job failed with unrecoverable exception', [
                'job_class' => get_class($job),
                'job_id' => method_exists($job, 'getJobId') ? $job->getJobId() : null,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            // Fail the job immediately without retries
            $job->fail($e);

            return;
        } catch (Throwable $e) {
            // Let other exceptions propagate (will retry if $tries allows)
            throw $e;
        }
    }
}
