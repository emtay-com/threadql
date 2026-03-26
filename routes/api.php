<?php

declare(strict_types=1);

use App\Http\Controllers\Api\DataSource\ListController as DataSourceListController;
use App\Http\Controllers\Api\DataSource\PingController as DataSourcePingController;
use App\Http\Controllers\Api\DataSource\PostController as DataSourcePostController;
use App\Http\Controllers\Api\DataSource\PutController as DataSourcePutController;
use App\Http\Controllers\Api\DataSource\ScanController as DataSourceScanController;
use App\Http\Controllers\Api\DataSource\TestConnectionController as DataSourceTestConnectionController;
use App\Http\Controllers\Api\Definition\DeleteController as DefinitionDeleteController;
use App\Http\Controllers\Api\Definition\ListController as DefinitionListController;
use App\Http\Controllers\Api\Definition\PostController as DefinitionPostController;
use App\Http\Controllers\Api\Definition\PutController as DefinitionPutController;
use App\Http\Controllers\Api\GeneralSetting\ListController as GeneralSettingListController;
use App\Http\Controllers\Api\GeneralSetting\PutController as GeneralSettingPutController;
use App\Http\Controllers\Api\LlmProvider\DeleteController as LlmProviderDeleteController;
use App\Http\Controllers\Api\LlmProvider\ListController as LlmProviderListController;
use App\Http\Controllers\Api\LlmProvider\PingController as LlmProviderPingController;
use App\Http\Controllers\Api\LlmProvider\PostController as LlmProviderPostController;
use App\Http\Controllers\Api\LlmProvider\PutController as LlmProviderPutController;
use App\Http\Controllers\Api\SlackUser\DeleteController as SlackUserDeleteController;
use App\Http\Controllers\Api\SlackUser\ListController as SlackUserListController;
use App\Http\Controllers\Api\SlackUser\PutController as SlackUserPutController;
use App\Http\Controllers\Api\SlackUser\RestoreController as SlackUserRestoreController;
use App\Http\Controllers\Api\Table\DeleteController as TableDeleteController;
use App\Http\Controllers\Api\Table\ListController as TableListController;
use App\Http\Controllers\Api\Table\PutController as TablePutController;
use App\Http\Controllers\Api\Table\RestoreController as TableRestoreController;
use App\Http\Controllers\Api\Tenant\ListController as TenantListController;
use App\Http\Controllers\Api\Tenant\ManifestController as TenantManifestController;
use App\Http\Controllers\Api\Tenant\PostController as TenantPostController;
use App\Http\Controllers\Api\Tenant\PutController as TenantPutController;
use App\Http\Controllers\Api\Tenant\TestSlackController as TenantTestSlackController;
use App\Http\Controllers\Api\TenantSetting\ListController as TenantSettingListController;
use App\Http\Controllers\Api\TenantSetting\PutController as TenantSettingPutController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\User\DeleteController as UserDeleteController;
use App\Http\Controllers\Api\User\ListController as UserListController;
use App\Http\Controllers\Api\User\PostController as UserPostController;
use App\Http\Controllers\Api\User\PutController as UserPutController;
use App\Http\Controllers\Slack\SlackEventsController;
use App\Http\Controllers\Slack\SlackInteractiveController;
use App\Http\Controllers\Slack\SlackSlashController;
use App\Http\Controllers\Tenant\DownloadCsvController;
use App\Http\Controllers\Tenant\PingController;
use App\Http\Middleware\EnsureSlackUserApproved;
use App\Http\Middleware\HandleSlackRetries;
use App\Http\Middleware\ValidateSlackSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Slack Events API
Route::post('/{tenant:uuid}/slack/events', [SlackEventsController::class, 'handle'])
    ->middleware([ValidateSlackSignature::class, HandleSlackRetries::class, EnsureSlackUserApproved::class])
    ->name('slack.events');

// Slack Slash Commands API
Route::post('/{tenant:uuid}/slack/commands', [SlackSlashController::class, 'handle'])
    ->middleware([ValidateSlackSignature::class, EnsureSlackUserApproved::class])
    ->name('slack.commands');

// Slack Interactive Components API
Route::post('/{tenant:uuid}/slack/interactive', [SlackInteractiveController::class, 'handle'])
    ->middleware([ValidateSlackSignature::class, EnsureSlackUserApproved::class])
    ->name('slack.interactive');

// Tenant Ping (no auth required - for connectivity verification)
Route::get('/{tenant:uuid}/ping', PingController::class)->name('tenant.ping');

// CSV Export Download (no auth - verified via HMAC signature)
Route::get('/{tenant:uuid}/download', DownloadCsvController::class)->name('tenant.download');

// Admin Token Creation (no auth required)
Route::post('/admin/token', [TokenController::class, 'create'])
    ->middleware('throttle:admin-login')
    ->name('admin.token.create');

// Admin Token Refresh (no auth middleware — uses HTTP-only cookie for refresh token)
Route::post('/admin/token/refresh', [TokenController::class, 'refresh'])
    ->name('admin.token.refresh');

// Admin Token Logout (clears refresh token cookie)
Route::post('/admin/token/logout', [TokenController::class, 'logout'])
    ->name('admin.token.logout');

// Admin API (auth protected)
Route::prefix('admin')->name('admin.')->middleware('auth:admin')->group(function () {
    Route::get('/me', [TokenController::class, 'me'])->name('me');

    // General Settings (global, master-only)
    Route::prefix('settings')->name('settings.')->middleware('admin.master')->group(function () {
        Route::get('/', GeneralSettingListController::class)->name('list');
        Route::put('/', GeneralSettingPutController::class)->name('update');
    });

    // Tenant Management
    Route::prefix('tenants')->name('tenants.')->group(function () {
        Route::get('/', TenantListController::class)->name('list');
        Route::post('/', TenantPostController::class)->middleware('admin.master')->name('create');
        Route::put('/{tenant:id}', TenantPutController::class)->middleware('admin.tenant')->name('update');
        Route::get('/{tenant:id}/manifest', TenantManifestController::class)->middleware('admin.tenant')->name(
            'manifest'
        );
        Route::get('/{tenant:id}/test-slack', TenantTestSlackController::class)->middleware('admin.tenant')->name(
            'test-slack'
        );
    });

    // User Management (master-only)
    Route::prefix('users')->name('users.')->middleware('admin.master')->group(function () {
        Route::get('/', UserListController::class)->name('list');
        Route::post('/', UserPostController::class)->name('create');
        Route::put('/{user}', UserPutController::class)->name('update');
        Route::delete('/{user}', UserDeleteController::class)->name('delete');
    });

    // LLM Provider Management (tenant-scoped)
    Route::prefix('tenants/{tenant:id}/llm-providers')->middleware('admin.tenant')->name('llm-providers.')->group(
        function () {
            Route::get('/', LlmProviderListController::class)->name('list');
            Route::post('/', LlmProviderPostController::class)->name('create');
            Route::put('/{llmProvider}', LlmProviderPutController::class)->name('update');
            Route::delete('/{llmProvider}', LlmProviderDeleteController::class)->name('delete');
            Route::get('/{llmProvider}/ping', LlmProviderPingController::class)->name('ping');
        }
    );

    // DataSource Management
    Route::prefix('tenants/{tenant:id}/datasources')->middleware('admin.tenant')->name('datasources.')->group(
        function () {
            Route::get('/', DataSourceListController::class)->name('list');
            Route::post('/', DataSourcePostController::class)->name('create');
            Route::post('/test-connection', DataSourceTestConnectionController::class)->name('test-connection');
            Route::put('/{datasource}', DataSourcePutController::class)->name('update');
            Route::get('/{datasource}/ping', DataSourcePingController::class)->name('ping');
            Route::post('/{datasource}/scan', DataSourceScanController::class)->name('scan');
        }
    );

    // Table Management
    Route::prefix('tenants/{tenant:id}/tables')->middleware('admin.tenant')->name('tables.')->group(function () {
        Route::get('/', TableListController::class)->name('list');
        Route::put('/{table}', TablePutController::class)->name('update');
        Route::patch('/{table}', TableRestoreController::class)->name('restore')->withTrashed();
        Route::delete('/{table}', TableDeleteController::class)->name('delete');
    });

    // Tenant Settings Management
    Route::prefix('tenants/{tenant:id}/settings')->middleware('admin.tenant')->name('settings.')->group(function () {
        Route::get('/', TenantSettingListController::class)->name('list');
        Route::put('/', TenantSettingPutController::class)->name('update');
    });

    // Definition Management
    Route::prefix('tenants/{tenant:id}/definitions')->middleware('admin.tenant')->name('definitions.')->group(
        function () {
            Route::get('/', DefinitionListController::class)->name('list');
            Route::post('/', DefinitionPostController::class)->name('create');
            Route::put('/{definition}', DefinitionPutController::class)->name('update');
            Route::delete('/{definition}', DefinitionDeleteController::class)->name('delete');
        }
    );

    // Slack User Management
    Route::prefix('tenants/{tenant:id}/slack-users')->middleware('admin.tenant')->name('slack-users.')->group(
        function () {
            Route::get('/', SlackUserListController::class)->name('list');
            Route::put('/{slackUser}', SlackUserPutController::class)->name('update');
            Route::delete('/{slackUser}', SlackUserDeleteController::class)->name('delete');
            Route::patch('/{slackUser}', SlackUserRestoreController::class)->name('restore')->withTrashed();
        }
    );
});
