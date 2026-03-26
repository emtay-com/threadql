<?php

declare(strict_types=1);

use App\Http\Middleware\RestrictToInternalNetwork;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP (Model Context Protocol) Routes
|--------------------------------------------------------------------------
|
| This file registers MCP server routes using the laravel/mcp package.
| The RestrictToInternalNetwork middleware ensures the MCP endpoint is
| only accessible from internal networks (localhost, private IPs).
|
*/

Route::middleware([RestrictToInternalNetwork::class])->group(function () {
    Mcp::web('/mcp', App\Mcp\ThreadqlServer::class);
});
