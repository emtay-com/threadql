<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\Query;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Feedback::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $categories = ['metric', 'filters', 'window', 'grouping', 'other'];
        $score = $this->faker->numberBetween(1, 10);

        return [
            'tenant_id' => Tenant::factory(),
            'query_id' => Query::factory(),
            'user_id' => $this->faker->regexify('[A-Z0-9]{9}'), // Slack user ID format
            'score' => $score,
            'category' => $this->faker->randomElement($categories),
            'note' => $this->generateNote($score),
        ];
    }

    /**
     * Indicate that the feedback is positive (high score).
     */
    public function positive(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => $this->faker->numberBetween(8, 10),
            'note' => $this->faker->optional(0.7)->randomElement([
                'Great query results!',
                'Exactly what I was looking for',
                'Very helpful insights',
                'Perfect for my analysis',
                'Excellent data presentation',
            ]),
        ]);
    }

    /**
     * Indicate that the feedback is negative (low score).
     */
    public function negative(): static
    {
        return $this->state(fn (array $attributes) => [
            'score' => $this->faker->numberBetween(1, 4),
            'note' => $this->faker->optional(0.8)->randomElement([
                'Results are not relevant',
                'Missing important data',
                'Query took too long',
                'Wrong metrics shown',
                'Need different filters',
            ]),
        ]);
    }

    /**
     * Indicate that the feedback is for metrics.
     */
    public function metric(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'metric',
        ]);
    }

    /**
     * Indicate that the feedback is for filters.
     */
    public function filters(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'filters',
        ]);
    }

    /**
     * Indicate that the feedback is for time window.
     */
    public function window(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'window',
        ]);
    }

    /**
     * Indicate that the feedback is for grouping.
     */
    public function grouping(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'grouping',
        ]);
    }

    /**
     * Generate appropriate note based on score.
     */
    private function generateNote(int $score): ?string
    {
        if ($score >= 8) {
            return $this->faker->optional(0.6)->randomElement([
                'Great query results!',
                'Exactly what I was looking for',
                'Very helpful insights',
                'Perfect for my analysis',
            ]);
        } elseif ($score >= 6) {
            return $this->faker->optional(0.4)->randomElement([
                'Good results, but could be better',
                'Mostly what I needed',
                'Helpful, but missing some details',
            ]);
        } elseif ($score >= 4) {
            return $this->faker->optional(0.7)->randomElement([
                'Results are somewhat relevant',
                'Need different approach',
                'Could use better filtering',
            ]);
        } else {
            return $this->faker->optional(0.8)->randomElement([
                'Results are not relevant',
                'Missing important data',
                'Query took too long',
                'Wrong metrics shown',
                'Need different filters',
            ]);
        }
    }
}

