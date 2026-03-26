<?php

declare(strict_types=1);

namespace App\Enums;

enum SettingEnum: string
{
    case MAX_ROWS_INLINE_CSV = 'max_rows_inline_csv';
    case MAX_PRIORITY_TABLES = 'max_priority_tables';
    case LLM_RESUME_MAX_STEPS = 'llm_resume_max_steps';
    case START_OF_WEEK = 'start_of_week';
    case WEEK_DEFINITION = 'week_definition';
    case MAX_TOKENS = 'max_tokens';
}
