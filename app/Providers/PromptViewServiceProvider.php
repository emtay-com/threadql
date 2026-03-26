<?php

declare(strict_types=1);

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for prompt view functionality
 */
class PromptViewServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton('prompt.defaults', function () {
            return [
                'now_utc' => Carbon::now()->utc()->toIso8601String(),
                'offset' => 0,
                'row_limit' => (int) config('prompt.defaults.row_limit', 100),
                'max_preview_rows' => (int) config('prompt.defaults.max_preview_rows', 20),
                'allowed_schemas_csv' => '',
                'default_window' => config('prompt.defaults.default_window', 'last 24 hours'),
                'default_grain' => config('prompt.defaults.default_grain', 'day'),
            ];
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel automatically loads resources/views, so no extra namespace needed
        // The prompts view path will be available as 'prompts.*'
    }
}
