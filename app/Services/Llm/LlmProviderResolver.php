<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\LlmProviderNotSetException;
use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class LlmProviderResolver
{
    /**
     * Resolve the first enabled LLM provider for a given tenant.
     *
     * @param Tenant|int $tenant The tenant or tenant ID
     * @return LlmProvider The resolved LLM provider
     */
    public function resolve(Tenant|int $tenant): LlmProvider
    {
        $tenant = $tenant instanceof Tenant ? $tenant : Tenant::find($tenant);

        if (! $tenant) {
            throw new LlmProviderNotSetException(0);
        }

        /** @var LlmProvider|null $provider */
        $provider = $tenant->llmProviders()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->first();

        if (! $provider) {
            Log::warning('No enabled LLM provider found for tenant', [
                'tenant_id' => $tenant->id,
            ]);

            throw new LlmProviderNotSetException($tenant->id);
        }

        return $this->validateProvider($provider);
    }

    /**
     * Resolve all enabled and validated LLM providers for a given tenant.
     *
     * @param Tenant|int $tenant The tenant or tenant ID
     * @return LlmProvider[] The resolved LLM providers, ordered by sort then id
     */
    public function resolveAll(Tenant|int $tenant): array
    {
        $tenant = $tenant instanceof Tenant ? $tenant : Tenant::find($tenant);

        if (! $tenant) {
            throw new LlmProviderNotSetException(0);
        }

        $providers = $tenant->llmProviders()
            ->where('enabled', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        if ($providers->isEmpty()) {
            Log::warning('No enabled LLM provider found for tenant', [
                'tenant_id' => $tenant->id,
            ]);

            throw new LlmProviderNotSetException($tenant->id);
        }

        $validated = [];
        /** @var LlmProvider $provider */
        foreach ($providers as $provider) {
            try {
                $validated[] = $this->validateProvider($provider);
            } catch (InvalidArgumentException) {
                Log::warning('Skipping unsupported LLM provider', [
                    'provider_id' => $provider->id,
                    'adapter' => $provider->adapter,
                ]);
            }
        }

        if (empty($validated)) {
            throw new LlmProviderNotSetException($tenant->id);
        }

        return $validated;
    }

    /**
     * Validate and return the provider if supported.
     *
     * @param LlmProvider $provider The LLM provider to validate
     * @return LlmProvider The validated provider
     */
    private function validateProvider(LlmProvider $provider): LlmProvider
    {
        $supported = [
            'anthropic',
            'deepseek',
            'gemini',
            'groq',
            'mistral',
            'ollama',
            'openai',
            'openrouter',
            'xai',
        ];

        if (! in_array($provider->adapter, $supported, true)) {
            throw new InvalidArgumentException("LLM adapter '{$provider->adapter}' is not implemented yet");
        }

        return $provider;
    }

    /**
     * Get the model name for a provider, with fallback to config defaults.
     *
     * @param LlmProvider $provider The LLM provider
     * @return string The model name to use
     */
    public function getModelName(LlmProvider $provider): string
    {
        if ($provider->model_name && ! empty($provider->model_name)) {
            return $provider->model_name;
        }

        // Fallback to config defaults based on adapter
        return match ($provider->adapter) {
            'openai' => config('llm.provider_defaults.openai.model'),
            default => throw new InvalidArgumentException("No default model for adapter '{$provider->adapter}'")
        };
    }
}
