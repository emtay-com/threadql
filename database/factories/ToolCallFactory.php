<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Query;
use App\Models\Tenant;
use App\Models\ToolCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ToolCall>
 */
class ToolCallFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = ToolCall::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'query_id' => Query::factory(),
            'tool' => $this->faker->randomElement(['run_sql_query', 'extract_table_ddl', 'create_definition', 'parse_definition']),
            'request_payload' => $this->faker->optional(0.8)->text(),
            'response_payload' => $this->faker->optional(0.8)->text(),
            'is_completed' => true,
            'anonymized_at' => null,
        ];
    }

    /**
     * Indicate that the tool call has no associated query.
     */
    public function withoutQuery(): static
    {
        return $this->state(fn (array $attributes) => [
            'query_id' => null,
        ]);
    }

    /**
     * Indicate that the tool call has been anonymized.
     */
    public function anonymized(): static
    {
        return $this->state(fn (array $attributes) => [
            'anonymized_at' => $this->faker->dateTimeBetween('-30 days', '-1 day'),
            'request_payload' => '/* anonymized */',
            'response_payload' => '/* anonymized */',
        ]);
    }

    /**
     * Indicate that the tool call is for a SQL query with realistic payload.
     */
    public function sqlQuery(): static
    {
        return $this->state(fn (array $attributes) => [
            'tool' => 'run_sql_query',
            'request_payload' => json_encode([
                'query' => 'SELECT COUNT(*) as total FROM users WHERE created_at > ?',
                'parameters' => ['2024-01-01'],
                'limit' => 1000,
            ], JSON_PRETTY_PRINT),
            'response_payload' => json_encode([
                'ok' => true,
                'result_kind' => 'aggregate',
                'aggregate' => ['label' => 'total', 'value' => 42],
                'row_count' => 1,
                'took_ms' => 15,
            ], JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * Indicate that the tool call has a function_call_id.
     */
    public function withFunctionCallId(): static
    {
        return $this->state(fn (array $attributes) => [
            'function_call_id' => 'fc_' . $this->faker->regexify('[a-zA-Z0-9]{20}'),
        ]);
    }

    /**
     * Indicate that the tool call has a session_id.
     */
    public function withSessionId(): static
    {
        return $this->state(fn (array $attributes) => [
            'session_id' => 'sess_' . $this->faker->regexify('[a-zA-Z0-9]{16}'),
        ]);
    }
}
