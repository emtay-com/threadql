<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Command;

use App\Command\TestCommand;
use App\Command\TestCommandResponse;
use App\Infrastructure\Command\CommandBus;
use App\Infrastructure\Command\CommandHandlerLocator;
use App\Infrastructure\Command\DomainCommandResponse;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CommandBusTest extends TestCase
{
    private CommandBus $commandBus;

    private CommandHandlerLocator $locator;

    private Container $container;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->locator = new CommandHandlerLocator($this->container);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->commandBus = new CommandBus($this->locator, $this->logger);
    }

    public function test_it_dispatches_command_successfully(): void
    {
        $command = new TestCommand('Hello World');

        $this->logger->expects($this->once())
            ->method('debug');

        $response = $this->commandBus->dispatch($command);

        $this->assertInstanceOf(TestCommandResponse::class, $response);
        $this->assertInstanceOf(DomainCommandResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Processed: Hello World', $response->getResult());
    }

    public function test_it_returns_correct_response_type(): void
    {
        $command = new TestCommand('Test Message');

        $response = $this->commandBus->dispatch($command);

        $this->assertInstanceOf(DomainCommandResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals([], $response->getErrors());
    }
}
