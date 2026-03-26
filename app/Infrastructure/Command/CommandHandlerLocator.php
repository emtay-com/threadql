<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Exception;
use Illuminate\Container\Container;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

/**
 * Locates and resolves command handlers for domain commands.
 *
 * This locator finds classes and interfaces that implement DomainCommandHandler in the
 * App\CommandHandlers namespace. It prioritizes interfaces (for dependency injection)
 * over concrete classes when multiple handlers can handle the same command.
 *
 * When an interface handler is found, it resolves the bound implementation from the
 * Laravel container. This allows for easy swapping of implementations in tests.
 *
 * All command handlers are preloaded by the CommandHandlerServiceProvider.
 */
class CommandHandlerLocator
{
    private Container $container;

    private array $handlerCache = [];

    private array $instanceCache = [];

    private array $handlerClasses = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Clear all caches. Primarily for testing purposes.
     */
    public function clearCaches(): void
    {
        $this->handlerCache = [];
        $this->instanceCache = [];
        $this->handlerClasses = [];
    }

    /**
     * Get the appropriate handler for the given command.
     */
    public function get(DomainCommand $command): DomainCommandHandler
    {
        $commandClass = get_class($command);

        if (isset($this->instanceCache[$commandClass])) {
            return $this->instanceCache[$commandClass];
        }

        if (isset($this->handlerCache[$commandClass])) {
            $handlerInstance = $this->resolveHandler($this->handlerCache[$commandClass]);
            $this->instanceCache[$commandClass] = $handlerInstance;

            return $handlerInstance;
        }

        $handler = $this->findHandler($command);

        $this->handlerCache[$commandClass] = $handler;

        $handlerInstance = $this->resolveHandler($handler);
        $this->instanceCache[$commandClass] = $handlerInstance;

        return $handlerInstance;
    }

    /**
     * Find a handler class that can handle the given command.
     *
     * @return string The handler class name
     */
    private function findHandler(DomainCommand $command): string
    {
        $commandClass = get_class($command);

        // Get all handler classes from the dedicated namespace
        $handlerClasses = $this->getHandlerClasses();

        // First, look for interfaces that can handle this command and are bound in the container
        foreach ($handlerClasses as $handlerClass) {
            $reflection = new ReflectionClass($handlerClass);
            if ($reflection->isInterface() && $this->canHandleCommand($handlerClass, $commandClass)) {
                // Check if this interface is bound in the container
                try {
                    $this->container->make($handlerClass);

                    return $handlerClass;
                } catch (Exception) {
                    // Interface is not bound, continue to next handler
                    continue;
                }
            }
        }

        // Then look for concrete classes
        foreach ($handlerClasses as $handlerClass) {
            $reflection = new ReflectionClass($handlerClass);
            if (! $reflection->isInterface() && $this->canHandleCommand($handlerClass, $commandClass)) {
                return $handlerClass;
            }
        }

        throw new RuntimeException(
            "No handler found for command: {$commandClass}. ".
            'Make sure you have a class implementing DomainCommandHandler '.
            'in the '.DomainCommandHandler::HANDLER_NAMESPACE.' namespace '.
            "with an __invoke method that accepts {$commandClass} as its first parameter."
        );
    }

    /**
     * Get all classes that implement DomainCommandHandler from the dedicated namespace.
     *
     * @return array<string>
     */
    private function getHandlerClasses(): array
    {
        if (! empty($this->handlerClasses)) {
            return $this->handlerClasses;
        }

        $handlerNamespace = DomainCommandHandler::HANDLER_NAMESPACE;
        $interfaces = [];
        $concreteClasses = [];

        // Check both declared classes and interfaces
        $allClasses = array_merge(get_declared_classes(), get_declared_interfaces());

        foreach ($allClasses as $className) {
            if (str_starts_with($className, $handlerNamespace.'\\')) {
                try {
                    $reflection = new ReflectionClass($className);

                    // Only include classes/interfaces that implement DomainCommandHandler
                    if ($reflection->implementsInterface(DomainCommandHandler::class)) {
                        if ($reflection->isInterface()) {
                            $interfaces[] = $className;
                        } else {
                            $concreteClasses[] = $className;
                        }
                    }
                } catch (ReflectionException) {
                    // Skip classes that can't be reflected
                    continue;
                }
            }
        }

        // Interfaces first (for dependency injection), then concrete classes
        $this->handlerClasses = array_merge($interfaces, $concreteClasses);

        return $this->handlerClasses;
    }

    /**
     * Check if a handler can handle the given command.
     */
    private function canHandleCommand(string $handlerClass, string $commandClass): bool
    {
        try {
            $handlerReflection = new ReflectionClass($handlerClass);

            // If it's an interface, check via its bound implementation
            if ($handlerReflection->isInterface()) {
                return $this->canInterfaceHandleCommand($handlerClass, $commandClass);
            }

            // For concrete classes, check the __invoke method directly
            return $this->canConcreteHandlerHandleCommand($handlerClass, $commandClass);

        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Check if a concrete handler can handle the given command.
     */
    private function canConcreteHandlerHandleCommand(string $handlerClass, string $commandClass): bool
    {
        try {
            $handlerReflection = new ReflectionClass($handlerClass);
            $invokeMethod = $handlerReflection->getMethod('__invoke');

            $parameters = $invokeMethod->getParameters();
            if (empty($parameters)) {
                return false;
            }

            $firstParameter = $parameters[0];
            $parameterType = $firstParameter->getType();
            if (! $parameterType) {
                return false;
            }

            // Handle union types and intersection types
            if ($parameterType instanceof \ReflectionUnionType || $parameterType instanceof \ReflectionIntersectionType) {
                $types = $parameterType->getTypes();
                foreach ($types as $type) {
                    if ($type instanceof \ReflectionNamedType && $type->getName() === $commandClass) {
                        return true;
                    }
                }

                return false;
            }

            if (! $parameterType instanceof \ReflectionNamedType) {
                return false;
            }

            // Handle single named type - check if it's the specific command class
            return $parameterType->getName() === $commandClass;

        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Check if an interface can handle a command by examining its bound implementation.
     */
    private function canInterfaceHandleCommand(string $interfaceClass, string $commandClass): bool
    {
        try {
            // Try to resolve the interface to get its implementation
            $implementation = $this->container->make($interfaceClass);
            $implementationClass = get_class($implementation);

            // Check if the implementation is a concrete class (not another interface)
            $implementationReflection = new ReflectionClass($implementationClass);
            if ($implementationReflection->isInterface()) {
                // If the implementation is also an interface, we can't handle it
                return false;
            }

            // Reuse the concrete handler logic for the implementation
            return $this->canConcreteHandlerHandleCommand($implementationClass, $commandClass);

        } catch (Exception) {
            // Interface is not bound or can't be resolved
            return false;
        }
    }

    /**
     * Resolve the handler from the container if it's an interface.
     */
    private function resolveHandler(string $handlerClass): DomainCommandHandler
    {
        $reflection = new ReflectionClass($handlerClass);

        // If it's an interface, resolve from container
        if ($reflection->isInterface()) {
            return $this->container->make($handlerClass);
        }

        // If it's a concrete class, instantiate it
        return $this->container->make($handlerClass);
    }
}
