<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default General Settings
    |--------------------------------------------------------------------------
    |
    | Default values for general settings. These are used when a setting
    | does not yet exist in the database.
    |
    */

    'max_rows_inline_csv' => '1000',
    'max_priority_tables' => '20',
    'llm_resume_max_steps' => '10',
    'start_of_week' => 'monday',
    'week_definition' => 'iso',
    'max_tokens' => '64000',
];
