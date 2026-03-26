<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sql;

use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Database\Strategies\QueryTimeoutStrategy;
use App\Models\Datasource;
use App\Services\Sql\TotalCountEstimator;
use Illuminate\Database\Connection;
use Mockery;
use PDO;
use Tests\TestCase;

/**
 * Test suite for TotalCountEstimator service
 */
class TotalCountEstimatorTest extends TestCase
{
    private TotalCountEstimator $estimator;

    private Mockery\MockInterface $connector;

    private Mockery\MockInterface $connection;

    private Mockery\MockInterface $pdo;

    private Datasource $datasource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->connection = Mockery::mock(Connection::class);
        $this->pdo = Mockery::mock(PDO::class);
        $this->datasource = new Datasource([
            'id' => 1,
            'connection_string' => 'test',
        ]);

        $this->estimator = new TotalCountEstimator($this->connector);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Simple SELECT queries (no subquery wrapping needed)
    // ---------------------------------------------------------------

    /**
     * Test building count query from simple SELECT
     */
    public function test_builds_count_query_from_simple_select(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE active = 1 ORDER BY name LIMIT 100';
        $expected = 'SELECT COUNT(*) AS total_count FROM users WHERE active = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    /**
     * Test building count query with complex WHERE and JOINs
     */
    public function test_builds_count_query_with_joins(): void
    {
        $originalSql = 'SELECT u.id, u.name, p.title FROM users u JOIN posts p ON u.id = p.user_id WHERE u.active = 1 AND p.published = 1 ORDER BY u.name';
        $expected = 'SELECT COUNT(*) AS total_count FROM users u JOIN posts p ON u.id = p.user_id WHERE u.active = 1 AND p.published = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_builds_count_query_with_left_join(): void
    {
        $originalSql = 'SELECT u.id, u.name FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.id IS NULL LIMIT 25';
        $expected = 'SELECT COUNT(*) AS total_count FROM users u LEFT JOIN orders o ON u.id = o.user_id WHERE o.id IS NULL';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_builds_count_query_with_multiple_joins(): void
    {
        $originalSql = 'SELECT c.name, o.id, p.title FROM customers c JOIN orders o ON c.id = o.customer_id JOIN products p ON o.product_id = p.id WHERE c.active = 1 ORDER BY c.name LIMIT 25 OFFSET 50';
        $expected = 'SELECT COUNT(*) AS total_count FROM customers c JOIN orders o ON c.id = o.customer_id JOIN products p ON o.product_id = p.id WHERE c.active = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_strips_limit_and_offset_from_simple_query(): void
    {
        $originalSql = 'SELECT id FROM users LIMIT 25 OFFSET 100';
        $expected = 'SELECT COUNT(*) AS total_count FROM users';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_strips_order_by_with_direction(): void
    {
        $originalSql = 'SELECT id, name FROM users ORDER BY name DESC, id ASC LIMIT 25';
        $expected = 'SELECT COUNT(*) AS total_count FROM users';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_subquery_in_where_clause(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE department_id IN (SELECT id FROM departments WHERE active = 1) ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM', $result);
        $this->assertStringContainsString('department_id IN (SELECT id FROM departments WHERE active = 1)', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    // ---------------------------------------------------------------
    // Window functions (no subquery wrapping — they don't change cardinality)
    // ---------------------------------------------------------------

    public function test_simple_select_with_window_function_does_not_wrap(): void
    {
        $originalSql = 'SELECT id, name, ROW_NUMBER() OVER(ORDER BY name) AS rn FROM users WHERE active = 1 ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        // Window functions don't change row cardinality, so FROM extraction is used
        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM users', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    // ---------------------------------------------------------------
    // GROUP BY queries (subquery wrapping required)
    // ---------------------------------------------------------------

    public function test_handles_group_by_queries_with_subquery(): void
    {
        $originalSql = 'SELECT customer_id, COUNT(*) FROM orders GROUP BY customer_id';
        $expected = 'SELECT COUNT(*) AS total_count FROM (SELECT customer_id, COUNT(*) FROM orders GROUP BY customer_id) t';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_group_by_with_where_clause(): void
    {
        $originalSql = 'SELECT customer_id, COUNT(*) as rental_count FROM rental WHERE store_id = 1 GROUP BY customer_id ORDER BY rental_count DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('WHERE store_id = 1', $result);
        $this->assertStringContainsString('GROUP BY customer_id', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_group_by_multiple_columns(): void
    {
        $originalSql = 'SELECT department_id, status, COUNT(*) as cnt FROM employees GROUP BY department_id, status ORDER BY cnt DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('GROUP BY department_id, status', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_group_by_with_join(): void
    {
        $originalSql = 'SELECT c.name, COUNT(o.id) AS order_count FROM customers c JOIN orders o ON c.id = o.customer_id GROUP BY c.name ORDER BY order_count DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('JOIN orders o ON c.id = o.customer_id', $result);
        $this->assertStringContainsString('GROUP BY c.name', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_group_by_with_aggregate_functions(): void
    {
        $originalSql = 'SELECT department_id, AVG(salary) as avg_salary, MAX(salary) as max_salary, MIN(salary) as min_salary FROM employees GROUP BY department_id ORDER BY avg_salary DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('GROUP BY department_id', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    // ---------------------------------------------------------------
    // HAVING clause (subquery wrapping required)
    // ---------------------------------------------------------------

    public function test_handles_having_clause(): void
    {
        $originalSql = 'SELECT customer_id, COUNT(*) as cnt FROM rental GROUP BY customer_id HAVING cnt > 5 LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('GROUP BY customer_id', $result);
        $this->assertStringContainsString('HAVING cnt > 5', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_having_with_aggregate_function(): void
    {
        $originalSql = 'SELECT department_id, COUNT(*) as cnt FROM employees GROUP BY department_id HAVING COUNT(*) > 10 ORDER BY cnt DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('HAVING COUNT(*) > 10', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_having_with_bound_parameter(): void
    {
        $originalSql = 'SELECT category_id, SUM(amount) as total FROM transactions GROUP BY category_id HAVING SUM(amount) > :min_amount ORDER BY total DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('HAVING SUM(amount) > :min_amount', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    // ---------------------------------------------------------------
    // DISTINCT queries (subquery wrapping required)
    // ---------------------------------------------------------------

    public function test_handles_distinct_queries_with_subquery(): void
    {
        $originalSql = 'SELECT DISTINCT c.first_name, c.last_name FROM customer c JOIN rental r ON c.customer_id = r.customer_id WHERE category = :category ORDER BY c.last_name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (SELECT DISTINCT', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_distinct_single_column(): void
    {
        $originalSql = 'SELECT DISTINCT email FROM users ORDER BY email LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (SELECT DISTINCT email FROM users', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    // ---------------------------------------------------------------
    // PostgreSQL: DISTINCT ON (subquery wrapping required)
    // ---------------------------------------------------------------

    public function test_handles_pg_distinct_on(): void
    {
        $originalSql = 'SELECT DISTINCT ON (department_id) department_id, employee_name, salary FROM employees ORDER BY department_id, salary DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (SELECT DISTINCT ON', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    // ---------------------------------------------------------------
    // Set operations: UNION, INTERSECT, EXCEPT (subquery wrapping required)
    // ---------------------------------------------------------------

    public function test_handles_union_queries(): void
    {
        $originalSql = 'SELECT id, name FROM customers UNION SELECT id, name FROM suppliers ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('UNION', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_union_all_queries(): void
    {
        $originalSql = 'SELECT id, name FROM customers UNION ALL SELECT id, name FROM suppliers ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('UNION ALL', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    public function test_handles_intersect_queries(): void
    {
        $originalSql = 'SELECT email FROM customers INTERSECT SELECT email FROM newsletter_subscribers ORDER BY email LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('INTERSECT', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    public function test_handles_except_queries(): void
    {
        $originalSql = 'SELECT email FROM all_users EXCEPT SELECT email FROM unsubscribed ORDER BY email LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('EXCEPT', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    // ---------------------------------------------------------------
    // PostgreSQL: CTEs (WITH ... AS)
    // ---------------------------------------------------------------

    public function test_handles_cte_without_group_by_via_fallback(): void
    {
        $originalSql = 'WITH active_users AS (SELECT id, name FROM users WHERE active = 1) SELECT id, name FROM active_users ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        // CTE won't match the simple FROM extraction regex, so falls back to subquery wrapping
        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('WITH active_users AS', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_cte_with_group_by(): void
    {
        $originalSql = 'WITH monthly_sales AS (SELECT product_id, SUM(amount) as total FROM sales GROUP BY product_id) SELECT product_id, total FROM monthly_sales ORDER BY total DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('WITH monthly_sales AS', $result);
        $this->assertStringContainsString('GROUP BY product_id', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    // ---------------------------------------------------------------
    // MySQL-specific patterns
    // ---------------------------------------------------------------

    public function test_handles_mysql_backtick_identifiers(): void
    {
        $originalSql = 'SELECT `u`.`id`, `u`.`name` FROM `users` `u` WHERE `u`.`active` = 1 ORDER BY `u`.`name` LIMIT 25';
        $expected = 'SELECT COUNT(*) AS total_count FROM `users` `u` WHERE `u`.`active` = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_mysql_group_by_with_backticks(): void
    {
        $originalSql = 'SELECT `department`, COUNT(*) as `cnt` FROM `employees` GROUP BY `department` ORDER BY `cnt` DESC LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('GROUP BY `department`', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    // ---------------------------------------------------------------
    // PostgreSQL-specific patterns
    // ---------------------------------------------------------------

    public function test_handles_pg_double_quote_identifiers(): void
    {
        $originalSql = 'SELECT "u"."id", "u"."name" FROM "users" "u" WHERE "u"."active" = 1 ORDER BY "u"."name" LIMIT 25';
        $expected = 'SELECT COUNT(*) AS total_count FROM "users" "u" WHERE "u"."active" = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_pg_cast_syntax(): void
    {
        $originalSql = 'SELECT id, created_at::date as day FROM events WHERE created_at > :start_date ORDER BY day LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM events', $result);
        $this->assertStringContainsString('WHERE created_at > :start_date', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    public function test_handles_pg_group_by_with_cast(): void
    {
        $originalSql = 'SELECT created_at::date as day, COUNT(*) as cnt FROM events GROUP BY created_at::date ORDER BY day LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('GROUP BY created_at::date', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
    }

    public function test_handles_pg_boolean_where(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE is_active IS TRUE AND is_deleted IS NOT TRUE ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM users', $result);
        $this->assertStringContainsString('WHERE is_active IS TRUE AND is_deleted IS NOT TRUE', $result);
    }

    public function test_handles_pg_ilike_operator(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE name ILIKE :pattern ORDER BY name LIMIT 25';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM users', $result);
        $this->assertStringContainsString('WHERE name ILIKE :pattern', $result);
    }

    // ---------------------------------------------------------------
    // Edge cases
    // ---------------------------------------------------------------

    public function test_handles_offset_without_limit(): void
    {
        // PostgreSQL allows OFFSET without LIMIT
        $originalSql = 'SELECT id, name FROM users ORDER BY id OFFSET 50';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM users', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('OFFSET', $result);
    }

    public function test_handles_query_with_no_where_clause(): void
    {
        $originalSql = 'SELECT id, name FROM users ORDER BY name LIMIT 25';
        $expected = 'SELECT COUNT(*) AS total_count FROM users';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_query_with_no_order_or_limit(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE active = 1';
        $expected = 'SELECT COUNT(*) AS total_count FROM users WHERE active = 1';

        $this->assertEquals($expected, $this->estimator->buildCountQuery($originalSql));
    }

    public function test_handles_multiline_query(): void
    {
        $originalSql = "SELECT\n  c.id,\n  c.name\nFROM\n  customers c\nWHERE\n  c.active = 1\nORDER BY\n  c.name\nLIMIT 25";
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM customers c WHERE c.active = 1', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_query_with_bound_parameters(): void
    {
        $originalSql = 'SELECT id, name FROM users WHERE status = :status AND role = :role ORDER BY name LIMIT :row_limit OFFSET :offset';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM users', $result);
        $this->assertStringContainsString('WHERE status = :status AND role = :role', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    public function test_handles_group_by_with_bound_parameters(): void
    {
        $originalSql = 'SELECT status, COUNT(*) as cnt FROM orders WHERE created_at > :start_date GROUP BY status ORDER BY cnt DESC LIMIT :row_limit';
        $result = $this->estimator->buildCountQuery($originalSql);

        $this->assertStringStartsWith('SELECT COUNT(*) AS total_count FROM (', $result);
        $this->assertStringContainsString('WHERE created_at > :start_date', $result);
        $this->assertStringContainsString('GROUP BY status', $result);
        $this->assertStringNotContainsString('ORDER BY', $result);
        $this->assertStringNotContainsString('LIMIT', $result);
    }

    // ---------------------------------------------------------------
    // Parameter filtering
    // ---------------------------------------------------------------

    public function test_filters_parameters_for_count_query(): void
    {
        $originalParams = [
            ':user_id' => 123,
            ':status' => 'active',
            ':row_limit' => 100,
            ':offset' => 0,
        ];

        $expected = [
            ':user_id' => 123,
            ':status' => 'active',
            ':row_limit' => 100,
            ':offset' => 0,
        ];

        $this->assertEquals($expected, $this->estimator->filterParametersForCount($originalParams));
    }

    public function test_filters_unbound_row_limit_and_offset(): void
    {
        $originalParams = [
            'user_id' => 123,
            'status' => 'active',
            'row_limit' => 100,
            'offset' => 0,
        ];

        $expected = [
            'user_id' => 123,
            'status' => 'active',
        ];

        $this->assertEquals($expected, $this->estimator->filterParametersForCount($originalParams));
    }

    public function test_filters_empty_parameters(): void
    {
        $this->assertEquals([], $this->estimator->filterParametersForCount([]));
    }

    // ---------------------------------------------------------------
    // Integration-style: estimateTotalCount
    // ---------------------------------------------------------------

    public function test_estimates_count_successfully(): void
    {
        $sql = 'SELECT id, name FROM users WHERE active = 1';
        $params = [
            ':active' => 1,
        ];

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->connector->shouldReceive('withConnection')
            ->andReturn(150);

        $result = $this->estimator->estimateTotalCount($sql, $params, $this->datasource);

        $this->assertEquals(150, $result);
    }

    public function test_returns_null_on_estimation_failure(): void
    {
        $sql = 'SELECT id FROM users';
        $params = [];

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->connector->shouldReceive('withConnection')
            ->andThrow(new \Exception('Database error'));

        $result = $this->estimator->estimateTotalCount($sql, $params, $this->datasource);

        $this->assertNull($result);
    }

    public function test_handles_zero_count(): void
    {
        $sql = 'SELECT id FROM users WHERE active = 0';
        $params = [
            ':active' => 0,
        ];

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->connector->shouldReceive('withConnection')
            ->andReturn(0);

        $result = $this->estimator->estimateTotalCount($sql, $params, $this->datasource);

        $this->assertEquals(0, $result);
    }

    public function test_estimation_timeout_returns_null_gracefully(): void
    {
        $sql = 'SELECT id FROM very_large_table';
        $params = [];

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->connector->shouldReceive('withConnection')
            ->andThrow(new \Exception('Query exceeded the maximum execution time'));

        $result = $this->estimator->estimateTotalCount($sql, $params, $this->datasource);

        $this->assertNull($result);
    }
}
