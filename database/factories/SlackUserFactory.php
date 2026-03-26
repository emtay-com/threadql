<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlackUser>
 */
class SlackUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slack_user_id' => 'U' . strtoupper($this->faker->regexify('[A-Z0-9]{10}')),
            'real_name' => $this->faker->name(),
            'display_name' => $this->faker->userName(),
            'avatar_url' => $this->faker->imageUrl(100, 100),
        ];
    }
}
