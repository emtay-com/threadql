<?php

return [
    'max_rows_export' => env('THREADQL_MAX_ROWS_EXPORT', 10000),
    'max_rows_async_export' => env('THREADQL_MAX_ROWS_ASYNC_EXPORT', 2000000),
    'disk' => env('EXPORT_DISK', 'exports'),
    'link_expiration_minutes' => 60,
    'download_secret' => env('EXPORT_DOWNLOAD_SECRET', env('APP_KEY')),
];
