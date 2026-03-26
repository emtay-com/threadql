<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Command;

use App\Command\TestCommand;
use App\Command\TestCommandResponse;
use App\CommandHandlers\TestCommandHandlerImplementation;
use App\CommandHandlers\TestCommandHandlerInterface;
use App\Infrastructure\Command\CommandHandlerLocator;
use App\Infrastructure\Command\DomainCommandResponse;
use App\Providers\CommandHandlerServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class CommandHandlerLocatorInterfaceTest extends TestCase
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

        // Clear caches to ensure clean state for interface preference tests
        $this->locator->clearCaches();
    }

    public function test_it_locates_handler_by_interface(): void
    {
        // Create a completely fresh container and locator for this test
        $freshContainer = new Container();

        // Register the CommandHandlerServiceProvider to load command handlers
        $provider = new CommandHandlerServiceProvider($freshContainer);
        $provider->register();

        $freshLocator = new CommandHandlerLocator($freshContainer);

        // Bind the interface to the implementation
        $freshContainer->singleton(TestCommandHandlerInterface::class, TestCommandHandlerImplementation::class);

        $command = new TestCommand('Hello World');

        $handler = $freshLocator->get($command);

        $this->assertInstanceOf(TestCommandHandlerImplementation::class, $handler);
        $this->assertInstanceOf(TestCommandHandlerInterface::class, $handler);
    }

    public function test_it_executes_handler_by_interface(): void
    {
        $this->container->bind(TestCommandHandlerInterface::class, TestCommandHandlerImplementation::class);

        $command = new TestCommand('Hello World');

        $handler = $this->locator->get($command);
        $response = $handler($command);

        $this->assertInstanceOf(TestCommandResponse::class, $response);
        $this->assertInstanceOf(DomainCommandResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertEquals('Processed: Hello World', $response->getResult());
    }

    public function test_it_handles_multiple_commands_with_interface(): void
    {
        $this->container->bind(TestCommandHandlerInterface::class, TestCommandHandlerImplementation::class);

        $command = new TestCommand('Hello World');

        $handler1 = $this->locator->get($command);
        $handler2 = $this->locator->get($command);

        $this->assertSame($handler1, $handler2);
    }
}
