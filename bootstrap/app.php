<?php

use App\Http\Middleware\EnsureMasterUser;
use App\Http\Middleware\EnsureTenantScope;
use Dotenv\Dotenv;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$toLoad = ['.env'];

$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;

if ((isset($_ENV['FORCE_TEST_DB']) && $_ENV['FORCE_TEST_DB'] == '1') || $env === 'test') {
    if (file_exists(dirname(__DIR__).'/.env.test')) {
        $toLoad[] = '.env.test';
    }

    putenv('APP_ENV=test');
    $_ENV['APP_ENV'] = 'test';
} elseif (getenv('APP_ENV') === 'local' && file_exists(dirname(__DIR__).'/.env.local')) {
    $toLoad[] = '.env.local';
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__), $toLoad, false);
$dotenv->load();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: '*',
            headers: Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->alias([
            'admin.master' => EnsureMasterUser::class,
            'admin.tenant' => EnsureTenantScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withProviders([App\Providers\SlackServiceProvider::class])
    ->create();
