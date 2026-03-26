<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sql;

use App\Services\Sql\AggregateDetector;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for AggregateDetector service
 */
class AggregateDetectorTest extends TestCase
{
    private AggregateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new AggregateDetector();
    }

    /**
     * Test that queries with GROUP BY are detected as aggregates
     */
    public function test_detects_group_by_as_aggregate(): void
    {
        $sql = 'SELECT customer_id, COUNT(*) as order_count FROM orders GROUP BY customer_id';
        $this->assertTrue($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test that queries with only aggregate functions in projection are detected as aggregates
     */
    public function test_detects_aggregate_only_projection(): void
    {
        $sql = 'SELECT COUNT(*) as total, SUM(amount) as sum_amount FROM orders';
        $this->assertTrue($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test that queries with mixed aggregate and non-aggregate columns are not detected as aggregates
     */
    public function test_detects_mixed_projection_as_non_aggregate(): void
    {
        $sql = 'SELECT customer_id, COUNT(*) as order_count FROM orders';
        $this->assertFalse($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test that simple SELECT queries are not detected as aggregates
     */
    public function test_detects_simple_select_as_non_aggregate(): void
    {
        $sql = 'SELECT id, name, email FROM users WHERE active = 1';
        $this->assertFalse($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test that queries with ORDER BY but no GROUP BY are not aggregates
     */
    public function test_detects_order_by_only_as_non_aggregate(): void
    {
        $sql = 'SELECT id, name FROM users ORDER BY name';
        $this->assertFalse($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test various aggregate function names
     */
    public function test_detects_various_aggregate_functions(): void
    {
        $aggregateQueries = [
            'SELECT AVG(price) FROM products',
            'SELECT MIN(created_at), MAX(updated_at) FROM users',
            'SELECT COUNT(DISTINCT user_id) FROM sessions',
            'SELECT SUM(revenue) / COUNT(*) FROM sales',
            'SELECT STDDEV(price) FROM products',
        ];

        foreach ($aggregateQueries as $sql) {
            $this->assertTrue($this->detector->isAggregateQuery($sql), "Failed for query: $sql");
        }
    }

    /**
     * Test that subqueries in FROM clause don't affect detection
     */
    public function test_handles_subqueries_correctly(): void
    {
        $sql = 'SELECT u.id, u.name FROM (SELECT id, name FROM users WHERE active = 1) u';
        $this->assertFalse($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test case insensitive detection
     */
    public function test_case_insensitive_detection(): void
    {
        $sql = 'select count(*) as total from orders group by customer_id';
        $this->assertTrue($this->detector->isAggregateQuery($sql));
    }

    /**
     * Test complex aggregate query with HAVING
     */
    public function test_complex_aggregate_with_having(): void
    {
        $sql = 'SELECT customer_id, SUM(amount) as total FROM orders GROUP BY customer_id HAVING SUM(amount) > 1000';
        $this->assertTrue($this->detector->isAggregateQuery($sql));
    }
}
