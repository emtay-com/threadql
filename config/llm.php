<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Large Language Model interactions including
    | default limits, timeouts, and provider settings.
    |
    */

    'default_row_limit' => env('LLM_DEFAULT_ROW_LIMIT', 25),

    'default_timeout_seconds' => env('LLM_DEFAULT_TIMEOUT_SECONDS', 15),

    'provider_defaults' => [
        'openai' => [
            'model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQL Execution Settings
    |--------------------------------------------------------------------------
    |
    | Settings for executing SQL queries with safety guardrails.
    |
    */

    'sql_execution' => [
        'hard_limit_cap' => env('LLM_SQL_HARD_LIMIT_CAP', 2000),
        'max_return_rows' => env('LLM_SQL_MAX_RETURN_ROWS', 1000),
        'query_timeout_seconds' => env('LLM_SQL_QUERY_TIMEOUT_SECONDS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | DDL Context Settings
    |--------------------------------------------------------------------------
    |
    | Settings for managing database DDL context in prompts.
    |
    */

    'ddl_context' => [
        'max_size_bytes' => env('LLM_DDL_MAX_SIZE_BYTES', 200000), // ~200KB
        'priority_threshold' => env('LLM_DDL_PRIORITY_THRESHOLD', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | DDL Fetch Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the fetch_table_ddls MCP tool that retrieves DDLs
    | for non-priority tables on demand.
    |
    */

    'max_ddl_tables_per_call' => env('LLM_MAX_DDL_TABLES_PER_CALL', 20),
    'max_ddl_chars' => env('LLM_MAX_DDL_CHARS', 32768), // ~32KB per DDL
    'include_recent_queries' => env('LLM_INCLUDE_RECENT_QUERIES', true),

    /*
    |--------------------------------------------------------------------------
    | Resume Settings
    |--------------------------------------------------------------------------
    |
    | Settings for resuming conversations with the Context Ledger format.
    | Controls how many previous steps to include and argument length limits.
    |
    */

    'resume' => [
        'max_args_len' => env('LLM_RESUME_MAX_ARGS_LEN', 200),
    ],
];
