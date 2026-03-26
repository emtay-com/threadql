<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LlmProvider>
 */
class LlmProviderFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = LlmProvider::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $providers = [
            [
                'name' => 'OpenAI GPT-4',
                'adapter' => 'openai',
                'url' => 'https://api.openai.com/v1',
                'model_name' => 'gpt-4',
            ],
            [
                'name' => 'OpenAI GPT-3.5',
                'adapter' => 'openai',
                'url' => 'https://api.openai.com/v1',
                'model_name' => 'gpt-3.5-turbo',
            ],
            [
                'name' => 'Anthropic Claude',
                'adapter' => 'anthropic',
                'url' => 'https://api.anthropic.com',
                'model_name' => 'claude-3-sonnet-20240229',
            ],
            [
                'name' => 'Local Ollama',
                'adapter' => 'ollama',
                'url' => 'http://localhost:11434',
                'model_name' => 'llama2',
            ],
            [
                'name' => 'Company Default',
                'adapter' => 'openai',
                'url' => 'https://api.openai.com/v1',
                'model_name' => 'gpt-4',
            ],
        ];

        $provider = $this->faker->randomElement($providers);

        return [
            'name' => $provider['name'],
            'adapter' => $provider['adapter'],
            'url' => $provider['url'],
            'model_name' => $provider['model_name'],
            'api_key' => $this->faker->optional(0.8)->regexify('[A-Za-z0-9]{32}'),
            'options' => null,
            'tenant_id' => null,
            'enabled' => true,
            'sort' => 0,
        ];
    }

    /**
     * Set the tenant for this provider.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenantId,
        ]);
    }

    /**
     * Mark the provider as enabled.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => true,
        ]);
    }

    /**
     * Mark the provider as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Set the sort order.
     */
    public function withSort(int $sort): static
    {
        return $this->state(fn (array $attributes) => [
            'sort' => $sort,
        ]);
    }

    /**
     * Set provider-specific options.
     *
     * @param  array<string, mixed>  $options
     */
    public function withOptions(array $options): static
    {
        return $this->state(fn (array $attributes) => [
            'options' => $options,
        ]);
    }

    /**
     * Indicate that the provider is OpenAI.
     */
    public function openai(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'OpenAI GPT-4',
            'adapter' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'model_name' => 'gpt-4',
        ]);
    }

    /**
     * Indicate that the provider is Anthropic.
     */
    public function anthropic(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Anthropic Claude',
            'adapter' => 'anthropic',
            'url' => 'https://api.anthropic.com',
            'model_name' => 'claude-3-sonnet-20240229',
        ]);
    }

    /**
     * Indicate that the provider is Ollama.
     */
    public function ollama(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Local Ollama',
            'adapter' => 'ollama',
            'url' => 'http://localhost:11434',
            'model_name' => 'llama2',
        ]);
    }
}
