<?php

declare(strict_types=1);

namespace Tests\Unit\CommandHandlers;

use App\Command\ExecuteParameterizedSelectCommand;
use App\Command\Results\SelectResult;
use App\CommandHandlers\ExecuteParameterizedSelectCommandHandler;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Database\Strategies\QueryTimeoutStrategy;
use App\Models\Datasource;
use App\Models\Query;
use App\Models\Tenant;
use App\Services\Sql\AggregateDetector;
use App\Services\Sql\TotalCountEstimator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExecuteParameterizedSelectCommandHandlerTest extends TestCase
{
    private ExecuteParameterizedSelectCommandHandler $handler;

    private DynamicDatabaseConnector $connector;

    private AggregateDetector $aggregateDetector;

    private TotalCountEstimator $totalCountEstimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connector = Mockery::mock(DynamicDatabaseConnector::class);
        $this->aggregateDetector = Mockery::mock(AggregateDetector::class);
        $this->totalCountEstimator = Mockery::mock(TotalCountEstimator::class);

        $this->handler = new ExecuteParameterizedSelectCommandHandler(
            $this->connector,
            $this->aggregateDetector,
            $this->totalCountEstimator
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_executes_parameterized_select_with_read_only_connection(): void
    {
        // Create test data using factories
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => 'SELECT id, name FROM users WHERE status = :status LIMIT :row_limit',
        ]);

        // Create the expected result
        $expectedResult = new SelectResult(
            columns: ['id', 'name'],
            rows: [
                [
                    'id' => 1,
                    'name' => 'John',
                    'status' => 'active',
                ],
                [
                    'id' => 2,
                    'name' => 'Jane',
                    'status' => 'active',
                ],
            ],
            rowCount: 2,
            truncated: false,
            limitApplied: 25
        );

        // Mock the timeout strategy
        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        // Mock the aggregate detector to return false (not an aggregate query)
        $this->aggregateDetector->shouldReceive('isAggregateQuery')
            ->once()
            ->andReturn(false);

        // Mock the total count estimator to return a count
        $this->totalCountEstimator->shouldReceive('estimateTotalCount')
            ->once()
            ->andReturn(150);

        // Mock the connector to return the expected result
        $this->connector->shouldReceive('withConnection')
            ->once()
            ->andReturn($expectedResult);

        // Create and execute command
        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: 'SELECT id, name FROM users WHERE status = :status LIMIT :row_limit',
            parameters: [
                'status' => 'active',
            ]
        );

        $commandResult = $this->handler->__invoke($command);

        $result = $commandResult->getResult();
        // Assert the result
        $this->assertInstanceOf(SelectResult::class, $result);

        $this->assertFalse($result->truncated);
        $this->assertEquals(25, $result->limitApplied);
    }

    #[Test]
    public function it_respects_row_limit_parameter(): void
    {
        // Create test data using factories
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => 'SELECT id, name FROM users LIMIT :row_limit',
        ]);

        // Create the expected result for row limit test
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => "User {$i}",
            ];
        }

        $expectedResult = new SelectResult(
            columns: ['id', 'name'],
            rows: $rows,
            rowCount: 50,
            truncated: true,
            limitApplied: 50
        );

        // Mock the timeout strategy
        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        // Mock the aggregate detector to return false (not an aggregate query)
        $this->aggregateDetector->shouldReceive('isAggregateQuery')
            ->once()
            ->andReturn(false);

        // Mock the total count estimator to return a count
        $this->totalCountEstimator->shouldReceive('estimateTotalCount')
            ->once()
            ->andReturn(200);

        // Mock the connector to return the expected result
        $this->connector->shouldReceive('withConnection')
            ->once()
            ->andReturn($expectedResult);

        // Create command with custom row limit
        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: 'SELECT id, name FROM users LIMIT :row_limit',
            parameters: [],
            rowLimit: 50
        );

        $commandResult = $this->handler->__invoke($command);
        $result = $commandResult->getResult();

        // Assert the result respects the limit
        $this->assertEquals(50, $result->rowCount);
    }

    #[Test]
    public function it_returns_error_for_invalid_query(): void
    {
        $command = new ExecuteParameterizedSelectCommand(
            queryId: 999, // Non-existent query
            sql: 'SELECT * FROM users',
            parameters: []
        );

        $response = $this->handler->__invoke($command);

        $this->assertFalse($response->isSuccess());
        $this->assertContains('Query not found', $response->getErrors());
    }

    #[Test]
    public function it_binds_offset_to_zero_when_sql_contains_offset_placeholder_but_parameter_not_provided(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => 'SELECT * FROM film LIMIT :row_limit OFFSET :offset',
        ]);

        $expectedResult = new SelectResult(
            columns: ['film_id', 'title'],
            rows: [[
                'film_id' => 1,
                'title' => 'Test Film',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->aggregateDetector->shouldReceive('isAggregateQuery')
            ->once()
            ->andReturn(false);

        $this->totalCountEstimator->shouldReceive('estimateTotalCount')
            ->once()
            ->andReturn(1);

        // The connector's withConnection receives a closure. We capture and verify
        // that offset defaults to 0 by checking the augmented result's parameters.
        $this->connector->shouldReceive('withConnection')
            ->once()
            ->andReturn($expectedResult);

        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: 'SELECT film_id, title FROM film JOIN film_category fc ON film.film_id = fc.film_id WHERE fc.category_id = :category_id LIMIT :row_limit OFFSET :offset',
            parameters: [
                ':category_id' => 5,
            ]
        );

        $commandResult = $this->handler->__invoke($command);
        $result = $commandResult->getResult();

        $this->assertTrue($commandResult->isSuccess());
        // The augmented result should have offset = 0 since it wasn't provided
        $this->assertEquals(0, $result->parameters['offset']);
    }

    #[Test]
    public function it_does_not_override_offset_when_already_provided_in_parameters(): void
    {
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => 'SELECT * FROM film LIMIT :row_limit OFFSET :offset',
        ]);

        $expectedResult = new SelectResult(
            columns: ['film_id', 'title'],
            rows: [[
                'film_id' => 26,
                'title' => 'Film Page 2',
            ]],
            rowCount: 1,
            truncated: false,
            limitApplied: 25
        );

        $this->connector->shouldReceive('getTimeoutStrategy')
            ->andReturn(Mockery::mock(QueryTimeoutStrategy::class));

        $this->aggregateDetector->shouldReceive('isAggregateQuery')
            ->once()
            ->andReturn(false);

        $this->totalCountEstimator->shouldReceive('estimateTotalCount')
            ->once()
            ->andReturn(50);

        $this->connector->shouldReceive('withConnection')
            ->once()
            ->andReturn($expectedResult);

        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: 'SELECT film_id, title FROM film LIMIT :row_limit OFFSET :offset',
            parameters: [
                ':offset' => 25,
            ]
        );

        $commandResult = $this->handler->__invoke($command);
        $result = $commandResult->getResult();

        $this->assertTrue($commandResult->isSuccess());
        // The augmented result should preserve the provided offset
        $this->assertEquals(25, $result->parameters['offset']);
    }

    #[Test]
    public function it_returns_error_for_non_select_sql(): void
    {
        // Create test data using factories
        $tenant = Tenant::factory()->create();
        $datasource = Datasource::factory()->create([
            'tenant_id' => $tenant->id,
            'dsn' => 'mysql://readonly_user:secret@127.0.0.1:3306/testdb',
        ]);

        $query = Query::factory()->create([
            'tenant_id' => $tenant->id,
            'raw_text' => 'SELECT id, name FROM users',
        ]);

        $command = new ExecuteParameterizedSelectCommand(
            queryId: $query->id,
            sql: 'UPDATE users SET status = "inactive"', // Non-SELECT
            parameters: []
        );

        $response = $this->handler->__invoke($command);

        $this->assertFalse($response->isSuccess());
        $this->assertContains('Only SELECT statements are allowed', $response->getErrors());
    }
}
