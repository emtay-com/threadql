<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Database\Strategies;

use App\Infrastructure\Database\Strategies\MysqlQueryTimeoutStrategy;
use Illuminate\Database\Connection;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MysqlQueryTimeoutStrategyTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MysqlQueryTimeoutStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new MysqlQueryTimeoutStrategy();
    }

    #[Test]
    public function it_sets_timeout_using_max_execution_time(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('statement')
            ->once()
            ->with('SET SESSION MAX_EXECUTION_TIME = 8000');

        $this->strategy->setTimeout($connection, 8);
    }

    #[Test]
    public function it_converts_seconds_to_milliseconds(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('statement')
            ->once()
            ->with('SET SESSION MAX_EXECUTION_TIME = 15000');

        $this->strategy->setTimeout($connection, 15);
    }
}
