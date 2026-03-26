<?php

declare(strict_types=1);

use Prism\Relay\Enums\Transport;

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Server Configurations
    |--------------------------------------------------------------------------
    |
    | Define your MCP server configurations here. Each server should have a
    | name as the key, and a configuration array with the appropriate settings.
    |
    */
    'servers' => [
        'app' => [
            'url' => env('MCP_APP_URL', 'https://your-app.test/mcp'), // your Laravel MCP endpoint
            'timeout' => 30,
            'transport' => Transport::Http,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Definition Cache Duration
    |--------------------------------------------------------------------------
    |
    | This value determines how long (in minutes) the tool definitions fetched
    | from MCP servers will be cached. Set to 0 to disable caching entirely.
    |
    */
    'cache_duration' => env('RELAY_TOOLS_CACHE_DURATION', 60),
];
