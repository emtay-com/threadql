<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database\Strategies;

use App\Infrastructure\Database\Strategies\PostgresQueryTimeoutStrategy;
use Illuminate\Database\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PostgresQueryTimeoutStrategyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private PostgresQueryTimeoutStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new PostgresQueryTimeoutStrategy();
    }

    #[Test]
    public function it_sets_timeout_using_statement_timeout(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('unprepared')
            ->once()
            ->with('SET statement_timeout = 8000');

        $this->strategy->setTimeout($connection, 8);
    }

    #[Test]
    public function it_converts_seconds_to_milliseconds(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('unprepared')
            ->once()
            ->with('SET statement_timeout = 15000');

        $this->strategy->setTimeout($connection, 15);
    }
}
