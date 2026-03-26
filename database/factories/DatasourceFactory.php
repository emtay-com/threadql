<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Datasource>
 */
class DatasourceFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Datasource::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $databases = [
            'mysql' => [
                'dsn' => 'mysql://readonly_user:secret@localhost:3306/analytics',
                'label' => 'MySQL Analytics',
            ],
            'postgresql' => [
                'dsn' => 'pgsql://readonly_user:secret@localhost:5432/analytics',
                'label' => 'PostgreSQL Analytics',
            ],
        ];

        $database = $this->faker->randomElement($databases);

        return [
            'tenant_id' => Tenant::factory(),
            'label' => $database['label'],
            'dsn' => $database['dsn'],
            'allowed_schemas_json' => ['public', 'analytics', 'reporting'],
            'default_limit' => $this->faker->randomElement([100, 200, 500, 1000]),
            'query_timeout_seconds' => $this->faker->randomElement([30, 60, 120, 300]),
            'timezone' => 'UTC',
        ];
    }

    /**
     * Indicate that the datasource is MySQL.
     */
    public function mysql(): static
    {
        return $this->state(fn (array $attributes) => [
            'dsn' => 'mysql://readonly_user:secret@localhost:3306/analytics',
            'label' => 'MySQL Analytics',
        ]);
    }

    /**
     * Indicate that the datasource is PostgreSQL.
     */
    public function postgresql(): static
    {
        return $this->state(fn (array $attributes) => [
            'dsn' => 'pgsql://readonly_user:secret@localhost:5432/analytics',
            'label' => 'PostgreSQL Analytics',
        ]);
    }

    /**
     * Indicate that the datasource has a short timeout.
     */
    public function shortTimeout(): static
    {
        return $this->state(fn (array $attributes) => [
            'query_timeout_seconds' => 30,
        ]);
    }

    /**
     * Indicate that the datasource has a long timeout.
     */
    public function longTimeout(): static
    {
        return $this->state(fn (array $attributes) => [
            'query_timeout_seconds' => 300,
        ]);
    }
}