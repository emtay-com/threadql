<?php

declare(strict_types=1);

namespace App\CommandHandlers\Slack;

use App\Command\Slack\ListCommand;
use App\Command\Slack\ListResponse;
use App\Enums\ListCommandOptions;
use App\Infrastructure\Command\DomainCommandHandler;
use App\Models\Definition;
use App\Models\Table;

/**
 * Handler for listing definitions or tables
 */
class SlackListCommandHandler implements DomainCommandHandler
{
    /**
     * Handle the list command
     */
    public function __invoke(ListCommand $command): ListResponse
    {
        return match ($command->option) {
            ListCommandOptions::DEFINITIONS => $this->listDefinitions($command->tenantId),
            ListCommandOptions::TABLES => $this->listTables($command->tenantId),
        };
    }

    /**
     * List definitions for tenant.
     */
    private function listDefinitions(int $tenantId): ListResponse
    {
        $definitions = $this->fetchDefinitions($tenantId);

        if ($definitions->isEmpty()) {
            return $this->createEmptyDefinitionsResponse($tenantId);
        }

        $content = $this->buildDefinitionsContent($definitions, $tenantId);

        return ListResponse::success($content);
    }

    /**
     * List tables for tenant.
     */
    private function listTables(int $tenantId): ListResponse
    {
        $tables = $this->fetchTables($tenantId);

        if ($tables->isEmpty()) {
            return $this->createEmptyTablesResponse($tenantId);
        }

        $content = $this->buildTablesContent($tables, $tenantId);

        return ListResponse::success($content);
    }

    /**
     * Fetch definitions for tenant.
     */
    private function fetchDefinitions(int $tenantId): mixed
    {
        return Definition::where('tenant_id', $tenantId)
            ->orderBy('priority', 'desc')
            ->orderBy('subject', 'asc')
            ->get();
    }

    /**
     * Create empty definitions response.
     */
    private function createEmptyDefinitionsResponse(int $tenantId): ListResponse
    {
        return ListResponse::success(
            "Definitions (tenant {$tenantId})\n```\nNo definitions found for this tenant.\n```"
        );
    }

    /**
     * Build definitions content with truncation.
     */
    private function buildDefinitionsContent(mixed $definitions, int $tenantId): string
    {
        $lines = $definitions->map(fn ($definition) => "{$definition->subject} => {$definition->definition}")
            ->toArray();
        $content = $this->truncateAndJoinLines($lines);

        return "Definitions (tenant {$tenantId})\n```\n{$content}\n```";
    }

    /**
     * Fetch tables for tenant.
     */
    private function fetchTables(int $tenantId): mixed
    {
        return Table::where('tenant_id', $tenantId)
            ->orderBy('priority', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Create empty tables response.
     */
    private function createEmptyTablesResponse(int $tenantId): ListResponse
    {
        return ListResponse::success("Tables (tenant {$tenantId})\n```\nNo tables found for this tenant.\n```");
    }

    /**
     * Build tables content with truncation.
     */
    private function buildTablesContent(mixed $tables, int $tenantId): string
    {
        $lines = $tables->map(function ($table) {
            $line = $table->name;
            if ($table->priority > 0) {
                $line .= " (priority: {$table->priority})";
            }

            return $line;
        })->toArray();

        $content = $this->truncateAndJoinLines($lines);

        return "Tables (tenant {$tenantId})\n```\n{$content}\n```";
    }

    /**
     * Truncate lines to max 200 and join with newlines.
     */
    private function truncateAndJoinLines(array $lines): string
    {
        $truncated = false;
        if (count($lines) > 200) {
            $lines = array_slice($lines, 0, 200);
            $truncated = true;
        }

        $content = implode("\n", $lines);
        if ($truncated) {
            $content .= "\n… (truncated)";
        }

        return $content;
    }
}
