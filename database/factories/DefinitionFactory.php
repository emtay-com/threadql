<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Definition;
use App\Models\Tenant;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Definition>
 */
class DefinitionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Definition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => $this->faker->regexify('U[A-Z0-9]{8}'),
            'thread_id' => null,
            'priority' => 0,
            'subject' => $this->faker->words(2, true),
            'definition' => $this->faker->sentence(),
        ];
    }

    /**
     * Indicate that the definition is associated with a thread.
     */
    public function withThread(): static
    {
        return $this->state(fn (array $attributes) => [
            'thread_id' => Thread::factory(),
        ]);
    }

    /**
     * Indicate that the definition has a specific priority.
     */
    public function withPriority(int $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }
}

