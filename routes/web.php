<?php

use App\Http\Controllers\Web\PanelController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('installed', [
        'version' => config('app.threadql_version'),
    ]);
});

Route::get('/panel/{any?}', [PanelController::class, 'index'])
    ->where('any', '.*')
    ->name('admin.panel');
