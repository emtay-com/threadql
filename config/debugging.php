<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tool Call Debugging Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for tool call logging, anonymization, and debugging features.
    |
    */

    'max_retention_tool_output' => (int) env('MAX_RETENTION_TOOL_OUTPUT', 7),

    'llm_http_debug' => (bool) env('LLM_HTTP_DEBUG', false),

    'max_tool_payload_size_kb' => (int) env('MAX_TOOL_PAYLOAD_SIZE_KB', 1024),

    'max_http_body_log_size_kb' => (int) env('MAX_HTTP_BODY_LOG_SIZE_KB', 100),

    /*
    |--------------------------------------------------------------------------
    | Sensitive Headers for Masking
    |--------------------------------------------------------------------------
    |
    | HTTP headers that contain sensitive information and should be masked
    | in debug logs.
    |
    */

    'sensitive_headers' => [
        'authorization',
        'x-api-key',
        'api-key',
        'bearer',
        'token',
        'secret',
        'password',
        'x-amz-security-token',
        'x-auth-token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sensitive Parameters for Masking
    |--------------------------------------------------------------------------
    |
    | Parameter names that contain sensitive information and should be masked
    | in tool call payloads.
    |
    */

    'sensitive_parameters' => [
        'password',
        'secret',
        'key',
        'token',
        'auth',
        'credential',
        'private',
        'sensitive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Anonymization Settings
    |--------------------------------------------------------------------------
    |
    | Settings for the tool call anonymization process.
    |
    */

    'anonymization' => [
        'chunk_size' => (int) env('TOOL_ANONYMIZATION_CHUNK_SIZE', 1000),
        'anonymized_content' => '/* anonymized */',
    ],
];
