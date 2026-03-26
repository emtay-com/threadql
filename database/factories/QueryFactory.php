<?php declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QueryStatus;
use App\Models\Query;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Query>
 */
class QueryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Query::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $queryTemplates = [
            'Show me total sales for the last 30 days',
            'How many new users signed up this month?',
            'What are our top 10 products by revenue?',
            'Show me customer retention rates by quarter',
            'How much revenue did we generate from mobile vs desktop?',
            'What is the average order value by customer segment?',
            'Show me inventory levels for all products',
            'How many orders are pending fulfillment?',
            'What is our conversion rate from visitors to customers?',
            'Show me sales performance by region',
        ];

        $rawText = $this->faker->randomElement($queryTemplates);

        return [
            'tenant_id' => Tenant::factory(),
            'thread_id' => \App\Models\Thread::factory(),
            'slack_event_id' => $this->faker->optional()->regexify('[A-Z0-9]{8}'),
            'channel_id' => $this->faker->regexify('[A-Z0-9]{9}'),
            'message_ts' => $this->faker->optional()->numerify('##########.######'),
            'status' => QueryStatus::RECEIVED->value,
            'user_id' => $this->faker->regexify('[A-Z0-9]{9}'), // Slack user ID format
            'raw_text' => $rawText,
            'plan_json' => $this->generatePlanJson($rawText),
            'sql_text' => $this->generateSqlText($rawText),
            'result_meta_json' => [
                'row_count' => $this->faker->numberBetween(1, 1000),
                'cols' => $this->faker->randomElements(['id', 'name', 'amount', 'date', 'status', 'category'], $this->faker->numberBetween(2, 6)),
                'truncated' => $this->faker->boolean(20),
                'limit_applied' => $this->faker->randomElement([100, 200, 500]),
                'is_aggregate' => false,
                'total_count' => $this->faker->optional(0.7)->numberBetween(1, 10000),
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => $this->faker->randomElement([100, 200, 500]),
                ],
            ],
            'latency_ms' => $this->faker->numberBetween(100, 5000),
            'score' => 0,
        ];
    }


    /**
     * Indicate that the query is fast.
     */
    public function fast(): static
    {
        return $this->state(fn (array $attributes) => [
            'latency_ms' => $this->faker->numberBetween(50, 500),
        ]);
    }

    /**
     * Indicate that the query is slow.
     */
    public function slow(): static
    {
        return $this->state(fn (array $attributes) => [
            'latency_ms' => $this->faker->numberBetween(3000, 10000),
        ]);
    }

    /**
     * Indicate that the query has many results.
     */
    public function manyResults(): static
    {
        return $this->state(fn (array $attributes) => [
            'result_meta_json' => [
                'row_count' => $this->faker->numberBetween(1000, 10000),
                'cols' => $this->faker->randomElements(['id', 'name', 'amount', 'date', 'status', 'category'], $this->faker->numberBetween(2, 6)),
                'truncated' => true,
                'limit_applied' => $this->faker->randomElement([100, 200, 500]),
                'is_aggregate' => false,
                'total_count' => $this->faker->numberBetween(10000, 100000),
                'parameters' => [
                    'offset' => 0,
                    'row_limit' => $this->faker->randomElement([100, 200, 500]),
                ],
            ],
        ]);
    }

    /**
     * Generate sample plan JSON for the query.
     */
    private function generatePlanJson(string $rawText): array
    {
        return [
            'tables' => $this->faker->randomElements(['users', 'orders', 'products', 'sales'], $this->faker->numberBetween(1, 3)),
            'filters' => [
                'date_range' => $this->faker->randomElement(['last_7_days', 'last_30_days', 'last_90_days']),
                'status' => $this->faker->optional()->randomElement(['active', 'completed', 'pending']),
            ],
            'aggregations' => $this->faker->randomElements(['sum', 'count', 'avg', 'max'], $this->faker->numberBetween(1, 3)),
            'group_by' => $this->faker->optional()->randomElements(['category', 'region', 'month'], $this->faker->numberBetween(1, 2)),
        ];
    }

    /**
     * Generate sample SQL text for the query.
     */
    private function generateSqlText(string $rawText): string
    {
        $sqlTemplates = [
            'Show me total sales for the last 30 days' => "SELECT SUM(amount) as total_sales FROM sales WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            'How many new users signed up this month?' => "SELECT COUNT(*) as new_users FROM users WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
            'What are our top 10 products by revenue?' => "SELECT p.name, SUM(s.amount) as revenue FROM products p JOIN sales s ON p.id = s.product_id GROUP BY p.id ORDER BY revenue DESC LIMIT 10",
            'Show me customer retention rates by quarter' => "SELECT quarter, COUNT(DISTINCT user_id) as retained_users FROM (SELECT QUARTER(created_at) as quarter, user_id FROM orders GROUP BY QUARTER(created_at), user_id HAVING COUNT(*) > 1) t GROUP BY quarter",
        ];

        return $sqlTemplates[$rawText] ?? "SELECT * FROM users LIMIT 100";
    }
}

