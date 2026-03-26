<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Command\DomainCommandHandler;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider that preloads all command handlers from the App\CommandHandlers namespace.
 *
 * This ensures that all command handlers are available in memory when the CommandHandlerLocator
 * needs to find them, eliminating the need for directory scanning.
 */
class CommandHandlerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Preload all command handlers to ensure they're available in get_declared_classes()
        $this->preloadCommandHandlers();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Preload all command handlers from the App\CommandHandlers namespace.
     *
     * This method ensures that all PHP files in the CommandHandlers directory
     * and its subdirectories are loaded into memory, making them available via get_declared_classes().
     */
    private function preloadCommandHandlers(): void
    {
        $handlerNamespace = DomainCommandHandler::HANDLER_NAMESPACE;
        $handlerDirectory = dirname(__DIR__).'/CommandHandlers';

        if (! is_dir($handlerDirectory)) {
            return;
        }

        $this->preloadHandlersFromDirectory($handlerDirectory, $handlerNamespace);
    }

    /**
     * Recursively preload handlers from a directory
     */
    private function preloadHandlersFromDirectory(string $directory, string $namespace): void
    {
        $files = scandir($directory);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $filePath = $directory.DIRECTORY_SEPARATOR.$file;

            if (is_dir($filePath)) {
                // Recursively scan subdirectories
                $subNamespace = $namespace.'\\'.$file;
                $this->preloadHandlersFromDirectory($filePath, $subNamespace);
            } elseif (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                // Load the class file to ensure it's available in get_declared_classes()
                // require_once is safe and will only load once
                require_once $filePath;
            }
        }
    }
}
