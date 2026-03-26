<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Pagination Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for table pagination in Slack responses.
    |
    */

    'page_size' => env('THREADQL_PAGE_SIZE', 25),

    'max_columns' => env('THREADQL_MAX_COLUMNS', 20),

    'max_rows_preview' => env('THREADQL_MAX_ROWS_PREVIEW', 25),

    /*
    |--------------------------------------------------------------------------
    | No Pagination Threshold
    |--------------------------------------------------------------------------
    |
    | If the total number of rows is less than or equal to this threshold,
    | pagination controls will not be shown.
    |
    */
    'no_pagination_threshold' => env('THREADQL_NO_PAGINATION_THRESHOLD', 35),
];
