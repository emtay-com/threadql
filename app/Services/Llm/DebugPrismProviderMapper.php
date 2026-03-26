<?php

declare(strict_types=1);

namespace App\Services\Llm;

use Illuminate\Support\Facades\Http;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Debug-enabled Prism Provider Mapper
 * Extends the base mapper to add HTTP wire logging capabilities
 */
class DebugPrismProviderMapper extends PrismProviderMapper
{
    private bool $debugEnabled = false;

    private ?RequestInterface $currentRequest = null;

    /**
     * Enable debug mode and inject debug middleware into Laravel HTTP client
     */
    public function enableDebugMode(): void
    {
        if (! $this->debugEnabled) {
            // Inject debug middleware into Laravel's HTTP client
            // This will affect all HTTP requests made by Prism since it uses Http:: facade
            Http::globalRequestMiddleware(function (RequestInterface $request) {
                // Store the request for use in response middleware
                $this->currentRequest = $request;
                // Log the request directly
                $this->logRequest($request);

                return $request;
            });

            Http::globalResponseMiddleware(function (ResponseInterface $response) {
                // Log the response using the stored request
                if ($this->currentRequest) {
                    $this->logResponse($this->currentRequest, $response);
                    // Clear the stored request
                    $this->currentRequest = null;
                }

                return $response;
            });

            $this->debugEnabled = true;
        }
    }

    /**
     * Log HTTP request (extracted from DebugMiddleware)
     */
    private function logRequest(RequestInterface $request): void
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $url = $uri->getScheme().'://'.$uri->getHost().$uri->getPath();
        if ($uri->getQuery()) {
            $url .= '?'.$uri->getQuery();
        }

        $this->writeLine("🔄 REQUEST: {$method} {$url}");

        // Log headers (masked)
        $this->writeLine('Headers:');
        foreach ($request->getHeaders() as $name => $values) {
            $maskedName = strtolower((string) $name);
            $value = implode(', ', $values);

            if ($this->isSensitiveHeader($maskedName)) {
                $value = $this->maskValue($value);
            }

            $this->writeLine("  {$name}: {$value}");
        }

        // Log body if present
        $body = $request->getBody();
        if ($body->getSize() > 0) {
            $bodyContent = $body->getContents();
            $body->rewind();

            $this->writeLine('Body:');
            $this->writeLine($this->formatJsonBody($bodyContent));
        }
    }

    /**
     * Log HTTP response (extracted from DebugMiddleware)
     */
    private function logResponse(RequestInterface $request, ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();

        $this->writeLine("📥 RESPONSE: {$statusCode} {$reasonPhrase}");

        // Log headers
        $this->writeLine('Headers:');
        foreach ($response->getHeaders() as $name => $values) {
            $this->writeLine("  {$name}: ".implode(', ', $values));
        }

        // Log body if present
        $body = $response->getBody();
        if ($body->getSize() > 0) {
            $bodyContent = $body->getContents();
            $body->rewind();

            $this->writeLine('Body:');
            $this->writeLine($this->formatJsonBody($bodyContent));
        }
        $this->writeLine(str_repeat('=', 80));
    }

    /**
     * Create a Prism builder with debug middleware
     */
    public function makePrismBuilderWithDebug($provider, array $messages): PendingRequest
    {
        // Enable debug mode if not already enabled
        $this->enableDebugMode();

        // Delegate to the parent's makePrismBuilder which correctly applies
        // the tenant's provider config (API key, URL, options)
        return $this->makePrismBuilder($provider, $messages);
    }

    /**
     * Generate text with debug logging
     */
    public function generateTextWithDebug($provider, array $messages): Response
    {
        $builder = $this->makePrismBuilderWithDebug($provider, $messages);
        $response = $builder->asText();

        return $response;
    }

    /**
     * Format body content, pretty-printing JSON when possible
     */
    private function formatJsonBody(string $body): string
    {
        // Try to decode as JSON and pretty print
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // If not JSON or if body is too large, return as-is or truncated
        if (strlen($body) > 5000) {
            return substr($body, 0, 5000)."\n... [truncated]";
        }

        return $body;
    }

    /**
     * Check if header name contains sensitive information
     */
    private function isSensitiveHeader(string $headerName): bool
    {
        $sensitiveHeaders = ['authorization', 'x-api-key', 'api-key', 'bearer', 'token', 'secret', 'password'];

        foreach ($sensitiveHeaders as $sensitive) {
            if (str_contains($headerName, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mask sensitive values
     */
    private function maskValue(string $value): string
    {
        if (strlen($value) <= 4) {
            return '***masked***';
        }

        // Keep first 2 and last 2 characters for context
        $start = substr($value, 0, 2);
        $end = substr($value, -2);
        $maskedLength = max(1, strlen($value) - 4);

        return $start.str_repeat('*', $maskedLength).$end;
    }

    /**
     * Write a line to STDOUT (console output)
     */
    private function writeLine(string $line): void
    {
        echo $line.PHP_EOL;
    }
}
