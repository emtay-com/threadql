<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\Slack\SlackClientFactory;
use App\Infrastructure\Slack\SlackMessageDispatcher;
use App\Infrastructure\Slack\SlackMessenger;
use App\Slack\Formatting\ResponseFormatter;
use App\Slack\Formatting\Scanners\TableScanner;
use Illuminate\Support\ServiceProvider;

class SlackServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the Slack client factory for tenant-scoped client creation
        $this->app->singleton(SlackClientFactory::class, function ($app) {
            return new SlackClientFactory();
        });

        $this->app->singleton(SlackMessenger::class, function ($app) {
            return new SlackMessenger(
                null, // No pre-configured client
                $app->make(SlackClientFactory::class),
                $app->make(ResponseFormatter::class)
            );
        });

        $this->app->singleton(SlackMessageDispatcher::class, function ($app) {
            return new SlackMessageDispatcher(
                $app->make(SlackMessenger::class),
                $app->make(ResponseFormatter::class)
            );
        });

        $this->app->singleton(ResponseFormatter::class, function ($app) {
            $formatter = new ResponseFormatter();

            // Register default scanners from config
            $scanners = config('slack-formatting.scanners', []);
            foreach ($scanners as $scannerClass) {
                if (class_exists($scannerClass)) {
                    $scanner = $app->make($scannerClass);
                    $formatter->addScanner($scanner);
                }
            }

            return $formatter;
        });

        $this->app->singleton(TableScanner::class, function ($app) {
            return new TableScanner(
                maxRows: config('slack-formatting.limits.table_rows', 25),
                cellMaxLength: config('slack-formatting.limits.cell_max_length', 2000)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
