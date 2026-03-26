<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Services\Llm\FallbackExceptionClassifier;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use PHPUnit\Framework\TestCase;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use RuntimeException;

class FallbackExceptionClassifierTest extends TestCase
{
    private FallbackExceptionClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new FallbackExceptionClassifier();
    }

    public function test_rate_limited_exception_is_fallbackable(): void
    {
        $e = PrismRateLimitedException::make();

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_provider_overloaded_exception_is_fallbackable(): void
    {
        $e = PrismProviderOverloadedException::make('anthropic');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_request_too_large_exception_is_fallbackable(): void
    {
        $e = PrismRequestTooLargeException::make('openai');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_connection_exception_is_fallbackable(): void
    {
        $e = new ConnectionException('Connection timed out');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_5xx_status_code_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('Anthropic', 500, 'server_error', 'Internal server error');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_502_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 502, 'bad_gateway', 'Bad gateway');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_503_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('Anthropic', 503, 'overloaded', 'Service unavailable');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_529_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('Anthropic', 529, 'overloaded', 'Overloaded');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_401_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 401, 'auth_error', 'Invalid API key');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_403_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 403, 'forbidden', 'Forbidden');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_404_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 404, 'not_found', 'Model not found');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_429_is_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 429, 'rate_limit', 'Rate limited');

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_422_is_not_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 422, 'invalid_request', 'Bad request');

        $this->assertFalse($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_with_400_is_not_fallbackable(): void
    {
        $e = PrismException::providerRequestErrorWithDetails('OpenAI', 400, 'bad_request', 'Bad request');

        $this->assertFalse($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_code_zero_with_request_exception_previous(): void
    {
        $requestException = new RequestException(new HttpResponse(new GuzzleResponse(500)));

        $e = new PrismException('Sending to model (gpt-4) failed: Server error', 0, $requestException);

        $this->assertTrue($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_code_zero_with_request_exception_422_previous(): void
    {
        $requestException = new RequestException(new HttpResponse(new GuzzleResponse(422)));

        $e = new PrismException('Sending to model (gpt-4) failed: Invalid', 0, $requestException);

        $this->assertFalse($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_code_zero_without_request_exception_previous(): void
    {
        $e = new PrismException('Some error', 0, new RuntimeException('generic'));

        $this->assertFalse($this->classifier->isFallbackable($e));
    }

    public function test_prism_exception_code_zero_without_previous(): void
    {
        $e = PrismException::promptOrMessages();

        $this->assertFalse($this->classifier->isFallbackable($e));
    }

    public function test_generic_exception_is_not_fallbackable(): void
    {
        $this->assertFalse($this->classifier->isFallbackable(new RuntimeException('generic error')));
    }

    public function test_generic_exception_subclass_is_not_fallbackable(): void
    {
        $this->assertFalse($this->classifier->isFallbackable(new \InvalidArgumentException('bad arg')));
    }
}
