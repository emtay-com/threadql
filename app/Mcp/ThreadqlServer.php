<?php

declare(strict_types=1);

namespace App\Mcp;

use Laravel\Mcp\Server;

/**
 * MCP Server for ThreadQL - Natural Language to SQL Query System
 */
class ThreadqlServer extends Server
{
    protected string $name = 'ThreadQL MCP Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server provides tools for executing SQL queries against tenant databases,
        fetching table schemas, and exporting query results to CSV.
    MARKDOWN;

    /**
     * @var array<int, Server\Tool|class-string<Server\Tool>>
     */
    protected array $tools = [
        RunSqlQueryTool::class,
        ExportCsvTool::class,
        FetchTableDdlsTool::class,
        RunQueryForCsvExportTool::class,
        RequestDefinitionTool::class,
    ];
}
