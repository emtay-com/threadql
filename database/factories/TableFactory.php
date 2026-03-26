<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Table>
 */
class TableFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = Table::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $tableTypes = [
            'users' => [
                'schema_name' => 'public',
                'name' => 'users',
                'priority' => 8,
                'row_count' => $this->faker->numberBetween(1000, 100000),
                'size_mb' => round($this->faker->randomFloat(4, 0.5, 500.0), 4),
            ],
            'orders' => [
                'schema_name' => 'analytics',
                'name' => 'orders',
                'priority' => 9,
                'row_count' => $this->faker->numberBetween(10000, 1000000),
                'size_mb' => round($this->faker->randomFloat(4, 10.0, 5000.0), 4),
            ],
            'products' => [
                'schema_name' => 'analytics',
                'name' => 'products',
                'priority' => 7,
                'row_count' => $this->faker->numberBetween(100, 10000),
                'size_mb' => round($this->faker->randomFloat(4, 0.1, 50.0), 4),
            ],
            'sales' => [
                'schema_name' => 'reporting',
                'name' => 'sales',
                'priority' => 9,
                'row_count' => $this->faker->numberBetween(50000, 5000000),
                'size_mb' => round($this->faker->randomFloat(4, 50.0, 10000.0), 4),
            ],
            'customers' => [
                'schema_name' => 'analytics',
                'name' => 'customers',
                'priority' => 8,
                'row_count' => $this->faker->numberBetween(5000, 500000),
                'size_mb' => round($this->faker->randomFloat(4, 5.0, 1000.0), 4),
            ],
            'inventory' => [
                'schema_name' => 'analytics',
                'name' => 'inventory',
                'priority' => 6,
                'row_count' => $this->faker->numberBetween(1000, 50000),
                'size_mb' => round($this->faker->randomFloat(4, 1.0, 200.0), 4),
            ],
        ];

        $tableType = $this->faker->randomElement($tableTypes);

        return [
            'tenant_id' => Tenant::factory(),
            'schema_name' => $tableType['schema_name'],
            'name' => $tableType['name'],
            'priority' => $tableType['priority'],
            'row_count' => $tableType['row_count'],
            'size_mb' => $tableType['size_mb'],
            'ddl_sql' => $this->generateDdlSql($tableType['name']),
        ];
    }

    /**
     * Indicate that the table is high priority.
     */
    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(8, 10),
        ]);
    }

    /**
     * Indicate that the table is low priority.
     */
    public function lowPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $this->faker->numberBetween(0, 5),
        ]);
    }

    /**
     * Indicate that the table is in the analytics schema.
     */
    public function analytics(): static
    {
        return $this->state(fn (array $attributes) => [
            'schema_name' => 'analytics',
        ]);
    }

    /**
     * Indicate that the table is in the reporting schema.
     */
    public function reporting(): static
    {
        return $this->state(fn (array $attributes) => [
            'schema_name' => 'reporting',
        ]);
    }

    /**
     * Indicate that the table is soft deleted (trashed).
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Generate sample DDL SQL for the table.
     */
    private function generateDdlSql(string $tableName): string
    {
        $ddlTemplates = [
            'users' => "CREATE TABLE users (\n  id BIGINT PRIMARY KEY AUTO_INCREMENT,\n  name VARCHAR(255) NOT NULL,\n  email VARCHAR(255) UNIQUE NOT NULL,\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n);",
            'orders' => "CREATE TABLE orders (\n  id BIGINT PRIMARY KEY AUTO_INCREMENT,\n  user_id BIGINT NOT NULL,\n  total_amount DECIMAL(10,2) NOT NULL,\n  status VARCHAR(50) NOT NULL,\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  FOREIGN KEY (user_id) REFERENCES users(id)\n);",
            'products' => "CREATE TABLE products (\n  id BIGINT PRIMARY KEY AUTO_INCREMENT,\n  name VARCHAR(255) NOT NULL,\n  price DECIMAL(10,2) NOT NULL,\n  category VARCHAR(100),\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n);",
            'sales' => "CREATE TABLE sales (\n  id BIGINT PRIMARY KEY AUTO_INCREMENT,\n  order_id BIGINT NOT NULL,\n  product_id BIGINT NOT NULL,\n  quantity INT NOT NULL,\n  unit_price DECIMAL(10,2) NOT NULL,\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  FOREIGN KEY (order_id) REFERENCES orders(id),\n  FOREIGN KEY (product_id) REFERENCES products(id)\n);",
        ];

        return $ddlTemplates[$tableName] ?? "CREATE TABLE {$tableName} (\n  id BIGINT PRIMARY KEY AUTO_INCREMENT,\n  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n);";
    }
}

