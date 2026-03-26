<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Slack Formatting Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for formatting LLM responses into Slack Block Kit components.
    | This includes table rendering, message limits, and feature toggles.
    |
    */

    'features' => [
        /*
        |--------------------------------------------------------------------------
        | Table Block Support
        |--------------------------------------------------------------------------
        |
        | Enable or disable the use of Slack's native table blocks.
        | When disabled, tables will fall back to formatted code blocks.
        |
        */
        'table_block' => env('SLACK_TABLE_BLOCK_ENABLED', true),
    ],

    'limits' => [
        /*
        |--------------------------------------------------------------------------
        | Maximum Table Rows
        |--------------------------------------------------------------------------
        |
        | Maximum number of rows to display in a table (excluding headers).
        | Tables with more rows will be truncated with a notice.
        |
        */
        'table_rows' => env('SLACK_TABLE_MAX_ROWS', 25),

        /*
        |--------------------------------------------------------------------------
        | Maximum Cell Length
        |--------------------------------------------------------------------------
        |
        | Maximum length for individual table cell content.
        | Longer content will be truncated with an ellipsis.
        |
        */
        'cell_max_length' => env('SLACK_CELL_MAX_LENGTH', 2000),

        /*
        |--------------------------------------------------------------------------
        | Maximum Message Length
        |--------------------------------------------------------------------------
        |
        | Maximum length for the text fallback in Slack messages.
        | Used when posting messages with blocks.
        |
        */
        'message_fallback_length' => env('SLACK_MESSAGE_FALLBACK_LENGTH', 150),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Scanners
    |--------------------------------------------------------------------------
    |
    | Default scanners to use for response formatting.
    | These will be automatically registered with the ResponseFormatter.
    |
    */
    'scanners' => [App\Slack\Formatting\Scanners\TableScanner::class],
];
