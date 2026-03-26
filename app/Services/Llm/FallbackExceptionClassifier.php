<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Http\Client\ConnectionException;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Throwable;

/**
 * Determines whether an LLM exception is eligible for provider fallback.
 */
class FallbackExceptionClassifier
{
    /**
     * Status codes that are considered fallbackable in the 4xx range.
     */
    private const FALLBACKABLE_4XX = [401, 402, 403, 404, 409, 410, 429];

    /**
     * Determine if the given exception should trigger a fallback to the next provider.
     *
     * @param Throwable $e The exception to classify
     * @return bool True if the exception is fallbackable
     */
    public function isFallbackable(Throwable $e): bool
    {
        if ($e instanceof PrismRateLimitedException) {
            return true;
        }

        if ($e instanceof PrismProviderOverloadedException) {
            return true;
        }

        if ($e instanceof PrismRequestTooLargeException) {
            return true;
        }

        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof PrismException) {
            return $this->classifyPrismException($e);
        }

        return false;
    }

    /**
     * Classify a PrismException by its status code.
     */
    private function classifyPrismException(PrismException $e): bool
    {
        $statusCode = $e->getCode();

        if ($statusCode > 0) {
            return $this->isStatusCodeFallbackable($statusCode);
        }

        // Code 0 — check if there's a RequestException as the previous exception
        $previous = $e->getPrevious();
        if ($previous instanceof \Illuminate\Http\Client\RequestException) {
            return $this->isStatusCodeFallbackable($previous->getCode());
        }

        return false;
    }

    /**
     * Check if an HTTP status code is fallbackable.
     */
    private function isStatusCodeFallbackable(int $statusCode): bool
    {
        // 5xx errors are always fallbackable
        if ($statusCode >= 500) {
            return true;
        }

        // Specific 4xx codes are fallbackable (but not 422)
        if ($statusCode >= 400) {
            return in_array($statusCode, self::FALLBACKABLE_4XX, true);
        }

        return false;
    }
}
