<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\SettingEnum;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Ledger\LedgerBuilder;
use App\Models\Definition;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\ToolCall;
use App\Prompt\Views\FollowupPromptView;
use App\Support\Ledger\SqlCallSummarizer;

class FollowUpPromptBuilder
{
    public function __construct(
        private readonly LlmProviderResolver $providerResolver,
        private readonly DynamicDatabaseConnector $connector = new DynamicDatabaseConnector,
    ) {
    }

    /**
     * Build a complete follow-up prompt for LLM interaction.
     *
     * @param  Query  $query  The follow-up query to build a prompt for
     * @param  Tenant  $tenant  The tenant context
     * @return array The message list for Prism
     */
    public function buildPrompt(Query $query, Tenant $tenant): array
    {
        $this->providerResolver->resolve($tenant);

        // Find the last successful run_sql_query tool call
        $lastSqlCall = $this->findLastSuccessfulRunSqlQuery($query->thread_id);

        // Build the user query text with handle replacement
        $userQueryText = $this->buildUserContent($query);

        // Build the data for the view
        $data = [
            'query_id' => $query->id,
            'last_sql_call_id' => $lastSqlCall?->id,
            'user_query_text' => $userQueryText,
            'sql_call' => new SqlCallSummarizer($lastSqlCall)
                ->summarize(),
        ];

        // Add DDLs if available
        $ddls = $this->buildDdlsData($tenant);
        if (! empty($ddls)) {
            $data['ddls'] = $ddls;
        }

        // Add definitions if available
        $definitions = $this->buildDefinitionsData($tenant);
        if (! empty($definitions)) {
            $data['definitions'] = $definitions;
        }

        // Add tables available if there are non-priority tables
        $tablesAvailable = $this->buildTablesAvailableData($tenant);
        if (! empty($tablesAvailable)) {
            $data['tables_available'] = $tablesAvailable;
        }

        // Add ledger for context
        $ledgerBuilder = new LedgerBuilder($query);
        $ledger = $ledgerBuilder->build();
        if (! empty($ledger)) {
            $data['ledger'] = $ledger;
        }

        // Add timezone and database driver data
        $datasource = $tenant->primaryDatasource();
        $view = new FollowupPromptView($data);
        $view->setTimezoneData($tenant->timezone ?? 'UTC', $datasource->timezone ?? 'UTC');
        if ($datasource) {
            $view->setDatabaseDriver($this->connector->getDriver($datasource));
        }

        return $view->getMessages();
    }

    /**
     * Find the last successful run_sql_query tool call in the thread.
     *
     * @param  int  $threadId  The thread ID to search in
     * @return ?ToolCall The last successful run_sql_query tool call if found
     */
    public function findLastSuccessfulRunSqlQuery(int $threadId): ?ToolCall
    {
        // Find the most recent successful run_sql_query tool call in this thread
        $toolCall = ToolCall::join('queries', 'tool_calls.query_id', '=', 'queries.id')
            ->where('queries.thread_id', $threadId)
            ->where('tool_calls.tool', 'run_sql_query')
            ->whereNotNull('tool_calls.response_payload')
            ->where('tool_calls.response_payload', 'not like', '%"ok":false%')
            ->orderBy('tool_calls.created_at', 'desc')
            ->orderBy('tool_calls.id', 'desc')
            ->select('tool_calls.*')
            ->first();

        return $toolCall;
    }

    /**
     * Build user content for follow-up queries.
     *
     * @param  Query  $query  The query to build content for
     * @return string The user content
     */
    private function buildUserContent(Query $query): string
    {
        // Strip Slack mention pattern: <@U01233456>
        $strippedQuery = preg_replace('/<@[A-Z0-9]+>/', '', $query->raw_text);

        return trim($strippedQuery);
    }

    /**
     * Build DDLs data for the view, limited by max_priority_tables setting.
     */
    private function buildDdlsData(Tenant $tenant): array
    {
        $maxPriorityTables = (int) GeneralSetting::resolve(SettingEnum::MAX_PRIORITY_TABLES)->value;
        $priorityThreshold = config('llm.ddl_context.priority_threshold', 0);

        $tables = Table::where('tenant_id', $tenant->id)
            ->where('priority', '>', $priorityThreshold)
            ->orderBy('priority', 'desc')
            ->orderBy('name')
            ->limit($maxPriorityTables)
            ->get();

        return $tables->map(function ($table) {
            return [
                'table' => $table->name,
                'row_count' => $table->row_count,
                'size_mb' => $table->size_mb,
                'ddl' => $table->ddl_sql,
            ];
        })->filter(function ($item) {
            return ! empty($item['ddl']);
        })->values()
            ->toArray();
    }

    /**
     * Build definitions data for the view
     */
    private function buildDefinitionsData(Tenant $tenant): array
    {
        $definitions = Definition::where('tenant_id', $tenant->id)
            ->orderBy('priority', 'desc')
            ->orderBy('subject')
            ->get();

        return $definitions->map(function ($definition) {
            return [
                'subject' => $definition->subject,
                'definition' => $definition->definition,
            ];
        })->toArray();
    }

    /**
     * Build tables available data for the view (non-priority + overflow priority tables).
     */
    private function buildTablesAvailableData(Tenant $tenant): array
    {
        $maxPriorityTables = (int) GeneralSetting::resolve(SettingEnum::MAX_PRIORITY_TABLES)->value;
        $priorityThreshold = config('llm.ddl_context.priority_threshold', 0);

        $includedTableIds = Table::where('tenant_id', $tenant->id)
            ->where('priority', '>', $priorityThreshold)
            ->orderBy('priority', 'desc')
            ->orderBy('name')
            ->limit($maxPriorityTables)
            ->pluck('id');

        return Table::where('tenant_id', $tenant->id)
            ->whereNotIn('id', $includedTableIds)
            ->orderBy('name')
            ->get(['name', 'row_count', 'size_mb'])
            ->map(fn ($table) => [
                'name' => $table->name,
                'row_count' => $table->row_count,
                'size_mb' => $table->size_mb,
            ])
            ->toArray();
    }
}
