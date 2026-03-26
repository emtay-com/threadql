<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\CommandHandlers\TestCommandHandler;
use App\CommandHandlers\TestCommandHandlerImplementation;
use App\CommandHandlers\TestCommandHandlerInterface;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Providers\CommandHandlerServiceProvider;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class CommandHandlerServiceProviderTest extends TestCase
{
    private CommandHandlerServiceProvider $provider;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Container();
        $this->provider = new CommandHandlerServiceProvider($this->container);
    }

    public function test_it_preloads_command_handlers(): void
    {
        // Register the provider to trigger preloading
        $this->provider->register();

        // Verify that the handler classes are now available
        $this->assertTrue(class_exists(TestCommandHandler::class));
        $this->assertTrue(interface_exists(TestCommandHandlerInterface::class));
        $this->assertTrue(class_exists(TestCommandHandlerImplementation::class));
    }

    public function test_preloaded_handlers_implement_domain_command_handler(): void
    {
        $this->provider->register();

        // Verify that the concrete handler implements the interface
        $this->assertInstanceOf(DomainCommandHandler::class, new TestCommandHandler());

        // Verify that the implementation implements the interface
        $this->assertInstanceOf(DomainCommandHandler::class, new TestCommandHandlerImplementation());
    }

    public function test_handlers_are_in_correct_namespace(): void
    {
        $this->provider->register();

        $expectedNamespace = DomainCommandHandler::HANDLER_NAMESPACE;

        $this->assertStringStartsWith($expectedNamespace, TestCommandHandler::class);
        $this->assertStringStartsWith($expectedNamespace, TestCommandHandlerInterface::class);
        $this->assertStringStartsWith($expectedNamespace, TestCommandHandlerImplementation::class);
    }
}
