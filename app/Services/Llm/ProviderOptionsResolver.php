<?php

declare(strict_types=1);

namespace App\Services\Llm;

/**
 * Resolves the available configuration options for each LLM provider adapter.
 *
 * These options correspond to the keys in config/prism.php for each provider,
 * excluding 'api_key' and 'url' which are stored as dedicated columns.
 */
class ProviderOptionsResolver
{
    /**
     * Keys that are reserved and must not be set via options.
     * These are stored as dedicated columns on the LlmProvider model.
     */
    private const RESERVED_KEYS = ['api_key', 'url'];

    /**
     * Get the available options for a specific adapter.
     *
     * @param  string  $adapter  The adapter key (e.g. 'openai', 'anthropic')
     * @return array<string, array{type: string, description: string, default: mixed}>
     */
    public function getOptionsForAdapter(string $adapter): array
    {
        $allOptions = $this->getAllAdapterOptions();

        return $allOptions[$adapter] ?? [];
    }

    /**
     * Get all adapter options keyed by adapter name.
     *
     * @return array<string, array<string, array{type: string, description: string, default: mixed}>>
     */
    public function getAllAdapterOptions(): array
    {
        return [
            'openai' => [
                'organization' => [
                    'type' => 'string',
                    'description' => 'OpenAI organization ID',
                    'default' => null,
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'OpenAI project ID',
                    'default' => null,
                ],
            ],
            'anthropic' => [
                'version' => [
                    'type' => 'string',
                    'description' => 'Anthropic API version',
                    'default' => '2023-06-01',
                ],
                'default_thinking_budget' => [
                    'type' => 'number',
                    'description' => 'Default thinking budget (tokens)',
                    'default' => 1024,
                ],
                'anthropic_beta' => [
                    'type' => 'string',
                    'description' => 'Comma-separated beta feature strings',
                    'default' => null,
                ],
            ],
            'ollama' => [],
            'mistral' => [],
            'groq' => [],
            'xai' => [],
            'gemini' => [],
            'deepseek' => [],
            'openrouter' => [],
        ];
    }

    /**
     * Get the list of supported adapter keys.
     *
     * @return array<int, string>
     */
    public function getSupportedAdapters(): array
    {
        return array_keys($this->getAllAdapterOptions());
    }

    /**
     * Get the default URL for each adapter, as defined in Prism's config.
     *
     * @return array<string, string>
     */
    public function getDefaultUrls(): array
    {
        return [
            'openai' => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'ollama' => 'http://localhost:11434',
            'mistral' => 'https://api.mistral.ai/v1',
            'groq' => 'https://api.groq.com/openai/v1',
            'xai' => 'https://api.x.ai/v1',
            'gemini' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'deepseek' => 'https://api.deepseek.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
        ];
    }

    /**
     * Build the provider config array for Prism from an LlmProvider model's stored data.
     *
     * Merges api_key, url, and any additional options into a single config array
     * suitable for passing to Prism's usingProviderConfig() or the third parameter of using().
     *
     * @param  string|null  $apiKey  The provider's API key
     * @param  string|null  $url  The provider's base URL
     * @param  array<string, mixed>|null  $options  Additional provider-specific options
     * @return array<string, mixed>
     */
    public function buildProviderConfig(?string $apiKey, ?string $url, ?array $options): array
    {
        $config = [];

        if ($apiKey !== null && $apiKey !== '') {
            $config['api_key'] = $apiKey;
        }

        if ($url !== null && $url !== '') {
            $config['url'] = $url;
        }

        if ($options !== null) {
            // Strip reserved keys to prevent options from overwriting api_key or url
            $safeOptions = array_diff_key($options, array_flip(self::RESERVED_KEYS));
            $config = array_merge($config, $safeOptions);
        }

        return $config;
    }
}
