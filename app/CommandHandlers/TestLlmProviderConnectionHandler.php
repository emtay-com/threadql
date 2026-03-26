<?php

declare(strict_types=1);

namespace App\CommandHandlers;

use App\Command\TestLlmProviderConnectionCommand;
use App\Command\TestLlmProviderConnectionResponse;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Models\LlmProvider;
use App\Services\Llm\ProviderOptionsResolver;
use Exception;
use Illuminate\Http\Client\Factory as HttpFactory;

class TestLlmProviderConnectionHandler implements DomainCommandHandler
{
    /**
     * Adapter-specific paths for the "list models" endpoint.
     *
     * Most OpenAI-compatible providers use /models.
     * Gemini and Ollama have different conventions.
     *
     * @var array<string, string>
     */
    private const MODELS_PATHS = [
        'openai' => '/models',
        'anthropic' => '/models',
        'groq' => '/models',
        'mistral' => '/models',
        'deepseek' => '/models',
        'xai' => '/models',
        'openrouter' => '/models',
        'ollama' => '/api/tags',
        'gemini' => '',
    ];

    public function __construct(
        private readonly ProviderOptionsResolver $optionsResolver,
        private readonly HttpFactory $http,
    ) {
    }

    /**
     * Test an LLM provider connection by hitting its list-models endpoint.
     */
    public function __invoke(TestLlmProviderConnectionCommand $command): TestLlmProviderConnectionResponse
    {
        $provider = LlmProvider::where('tenant_id', $command->tenantId)
            ->where('id', $command->llmProviderId)
            ->firstOrFail();

        try {
            $url = $this->buildModelsUrl($provider);
            $response = $this->sendRequest($provider, $url);

            if ($response->successful()) {
                return TestLlmProviderConnectionResponse::success();
            }

            return TestLlmProviderConnectionResponse::failed("HTTP {$response->status()}: {$response->body()}");
        } catch (Exception $e) {
            return TestLlmProviderConnectionResponse::failed($e->getMessage());
        }
    }

    /**
     * Build the full URL for the list-models endpoint.
     */
    private function buildModelsUrl(LlmProvider $provider): string
    {
        $baseUrl = $provider->url ?: $this->getDefaultUrl($provider->adapter);
        $baseUrl = rtrim($baseUrl, '/');

        $path = self::MODELS_PATHS[$provider->adapter] ?? '/models';

        return $baseUrl.$path;
    }

    /**
     * Adapters that use a custom header instead of Bearer token for authentication.
     *
     * @var array<string, string>
     */
    private const API_KEY_HEADERS = [
        'anthropic' => 'x-api-key',
        'gemini' => 'x-goog-api-key',
    ];

    /**
     * Send the HTTP request with appropriate authentication headers.
     */
    private function sendRequest(LlmProvider $provider, string $url): \Illuminate\Http\Client\Response
    {
        $client = $this->http->timeout(10)
            ->connectTimeout(5);

        if ($provider->api_key) {
            $headerName = self::API_KEY_HEADERS[$provider->adapter] ?? null;

            if ($headerName !== null) {
                $client = $client->withHeaders([
                    $headerName => $provider->api_key,
                ]);
            } else {
                $client = $client->withToken($provider->api_key);
            }
        }

        if ($provider->adapter === 'anthropic') {
            $version = $provider->options['version'] ?? '2023-06-01';
            $client = $client->withHeaders([
                'anthropic-version' => $version,
            ]);
        }

        return $client->get($url);
    }

    /**
     * Get the default base URL for an adapter.
     */
    private function getDefaultUrl(string $adapter): string
    {
        $defaults = $this->optionsResolver->getDefaultUrls();

        return $defaults[$adapter] ?? throw new \InvalidArgumentException("No default URL for adapter: {$adapter}");
    }
}
