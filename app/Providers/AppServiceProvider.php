<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\AdminUserProvider;
use App\Domain\Export\ExportCsvService;
use App\Infrastructure\Command\CommandBus;
use App\Infrastructure\Command\CommandHandlerLocator;
use App\Infrastructure\Command\DomainCommandBus;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Slack\SlackMessenger;
use App\Infrastructure\Ssh\SshTunnelClient;
use App\Models\Datasource;
use App\Models\Definition;
use App\Models\LlmProvider;
use App\Models\SlackUser;
use App\Models\Table;
use App\Policies\DatasourcePolicy;
use App\Policies\DefinitionPolicy;
use App\Policies\LlmProviderPolicy;
use App\Policies\SlackUserPolicy;
use App\Policies\TablePolicy;
use App\Services\Slack\SlackChannelRateLimiter;
use App\Services\Sql\AggregateDetector;
use App\Services\Sql\TotalCountEstimator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SshTunnelClient::class, function ($app) {
            return new SshTunnelClient(config('services.ssh_tunnel.url'));
        });

        $this->app->singleton(DynamicDatabaseConnector::class, function ($app) {
            return new DynamicDatabaseConnector(sshTunnelClient: $app->make(SshTunnelClient::class));
        });

        $this->app->singleton(DomainCommandBus::class, function ($app) {
            return new CommandBus($app->make(CommandHandlerLocator::class), $app->make('log'));
        });

        $this->app->singleton(AggregateDetector::class, function ($app) {
            return new AggregateDetector;
        });

        $this->app->singleton(TotalCountEstimator::class, function ($app) {
            return new TotalCountEstimator($app->make(DynamicDatabaseConnector::class));
        });

        $this->app->singleton(ExportCsvService::class, function ($app) {
            return new ExportCsvService(
                $app->make(DynamicDatabaseConnector::class),
                $app->make(SlackMessenger::class)
            );
        });

        $this->app->singleton(SlackChannelRateLimiter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register custom admin user provider
        Auth::provider('admin', function ($app, array $config) {
            return new AdminUserProvider;
        });

        RateLimiter::for('admin-login', function (Request $request): Limit {
            $username = Str::lower(trim((string) $request->input('username', '')));
            $key = ($username !== '' ? $username : 'unknown').'|'.$request->ip();

            return Limit::perMinute(3)->by($key);
        });

        // Register policies
        Gate::policy(Table::class, TablePolicy::class);
        Gate::policy(Definition::class, DefinitionPolicy::class);
        Gate::policy(SlackUser::class, SlackUserPolicy::class);
        Gate::policy(Datasource::class, DatasourcePolicy::class);
        Gate::policy(LlmProvider::class, LlmProviderPolicy::class);
    }
}
