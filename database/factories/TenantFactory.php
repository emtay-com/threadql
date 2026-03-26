<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'bot_name' => null,
            'uuid' => (string) Str::uuid(),
            'timezone' => 'UTC',
        ];
    }

    /**
     * Indicate that the tenant has a custom bot name.
     */
    public function withBotName(?string $botName = null): static
    {
        return $this->state(fn (array $attributes) => [
            'bot_name' => $botName ?? $this->faker->firstName() . ' Bot',
        ]);
    }

    /**
     * Indicate that the tenant has no LLM provider.
     */
    public function withoutLlmProvider(): static
    {
        return $this;
    }

    /**
     * Create a tenant with an associated LLM provider.
     */
    public function withLlmProvider(array $attributes = []): static
    {
        return $this->afterCreating(function (Tenant $tenant) use ($attributes) {
            LlmProvider::factory()->create(array_merge(
                ['tenant_id' => $tenant->id],
                $attributes
            ));
        });
    }

    /**
     * Indicate that the tenant has Slack credentials.
     */
    public function withSlackCredentials(): static
    {
        return $this->state(fn (array $attributes) => [
            'slack_app_id' => 'A' . $this->faker->regexify('[0-9]{10}'),
            'slack_client_id' => $this->faker->regexify('[0-9]{12}\.[0-9]{12}'),
            'slack_bot_token' => 'xoxb-' . $this->faker->regexify('[0-9]{12}-[0-9]{12}-[A-Za-z0-9]{24}'),
            'slack_signing_secret' => $this->faker->regexify('[a-f0-9]{32}'),
            'slack_verification_token' => $this->faker->regexify('[A-Za-z0-9]{24}'),
        ]);
    }
}
