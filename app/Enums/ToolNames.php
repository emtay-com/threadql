<?php

declare(strict_types=1);

namespace App\Enums;

enum ToolNames: string
{
    case RUN_SQL_QUERY = 'run_sql_query';
    case EXPORT_CSV = 'export_csv';
    case RUN_QUERY_FOR_CSV_EXPORT = 'run_query_for_csv_export';
}
