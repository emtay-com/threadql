<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Command;

use App\Command\TestCommand;
use App\Command\TestCommandResponse;
use App\CommandHandlers\TestCommandHandler;
use App\Infrastructure\Command\CommandHandlerLocator;
use App\Infrastructure\Command\DomainCommand;
use App\Infrastructure\Command\DomainCommandResponse;
use App\Providers\CommandHandlerServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CommandHandlerLocatorTest extends TestCase
{
    private CommandHandlerLocator $locator;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();

        // Register the CommandHandlerServiceProvider to load command handlers
        $provider = new CommandHandlerServiceProvider($this->container);
        $provider->register();

        $this->locator = new CommandHandlerLocator($this->container);
    }

    public function test_it_locates_handler_for_command(): void
    {
        $command = new TestCommand('Hello World');

        $handler = $this->locator->get($command);

        $this->assertInstanceOf(TestCommandHandler::class, $handler);
    }

    public function test_it_executes_handler_correctly(): void
    {
        $command = new TestCommand('Hello World');

        $handler = $this->locator->get($command);
        $response = $handler($command);

        $this->assertInstanceOf(TestCommandResponse::class, $response);
        $this->assertInstanceOf(DomainCommandResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Processed: Hello World', $response->getResult());
    }

    public function test_it_caches_handler_instances(): void
    {
        $command1 = new TestCommand('First');
        $command2 = new TestCommand('Second');

        $handler1 = $this->locator->get($command1);
        $handler2 = $this->locator->get($command2);

        // Should return the same handler instance for the same command type
        $this->assertSame($handler1, $handler2);
    }

    public function test_it_throws_exception_for_unknown_command(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No handler found for command');

        // Create a mock command that doesn't have a handler
        $mockCommand = new class implements DomainCommand
        {
            public string $message = 'Unknown';
        };

        $this->locator->get($mockCommand);
    }
}
