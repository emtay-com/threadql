<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\QueryStatus;
use App\Enums\SettingEnum;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Models\Definition;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Table;
use App\Models\Tenant;
use App\Prompt\Views\InitialPromptView;
use App\Services\Llm\Builders\MessageCollectionBuilder;
use App\Services\Llm\Builders\PromptDataContextBuilder;
use App\Support\Ledger\PromptLedgerBuilder;

class PromptBuilder
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector = new DynamicDatabaseConnector,
    ) {
    }

    /**
     * Build a complete prompt for LLM interaction.
     *
     * @param  Query  $query  The query to build a prompt for
     * @param  Tenant  $tenant  The tenant context
     * @return array The message list for Prism
     */
    public function buildPrompt(Query $query, Tenant $tenant): array
    {
        // If this is the first run (RECEIVED status), build the initial prompt
        if ($query->status === QueryStatus::RECEIVED->value) {
            return $this->buildInitialPrompt($query, $tenant);
        }

        // Otherwise, reconstruct the conversation from tool call history
        return $this->buildResumptionPrompt($query, $tenant);
    }

    /**
     * Build the initial prompt for a new query.
     *
     * @param  Query  $query  The query to build a prompt for
     * @param  Tenant  $tenant  The tenant context
     * @return array The message list for Prism
     */
    private function buildInitialPrompt(Query $query, Tenant $tenant): array
    {
        $userQueryText = $this->buildUserContent($query->raw_text);
        $datasource = $tenant->primaryDatasource();

        $data = PromptDataContextBuilder::create()
            ->withQueryId($query->id)
            ->withUserQueryText($userQueryText)
            ->withDdls($this->buildDdlsData($tenant))
            ->withDefinitions($this->buildDefinitionsData($tenant))
            ->withTablesAvailable($this->buildTablesAvailableData($tenant))
            ->build();

        $view = new InitialPromptView($data);
        $view->setTimezoneData($tenant->timezone ?? 'UTC', $datasource->timezone ?? 'UTC');
        if ($datasource) {
            $view->setDatabaseDriver($this->connector->getDriver($datasource));
        }

        return $view->getMessages();
    }

    /**
     * Build the resumption prompt by reconstructing the conversation from tool call history.
     *
     * @param  Query  $query  The query to build a prompt for
     * @param  Tenant  $tenant  The tenant context
     * @return array The message list for Prism
     */
    private function buildResumptionPrompt(Query $query, Tenant $tenant): array
    {
        $messageBuilder = MessageCollectionBuilder::create();

        // 1. Build and add system message
        $systemContent = $this->buildSystemContent($tenant);
        $messageBuilder->addSystemMessage($systemContent);

        // 2. Add original user message
        $originalUserContent = 'query_id: '.$query->id."\n\n".$this->buildUserContent($query->raw_text);
        $messageBuilder->addUserMessage($originalUserContent);

        // 3. Add context ledger (assistant message)
        $ledger = PromptLedgerBuilder::buildForQuery($query->id);
        $messageBuilder->addAssistantMessage($ledger);

        // 4. Add thread definitions if available
        $threadDefinitions = $this->buildThreadDefinitionsData($query->thread_id);
        $messageBuilder->addDefinitionsMessage($threadDefinitions);

        return $messageBuilder->build();
    }

    /**
     * Build system content for resumption prompts
     */
    private function buildSystemContent(Tenant $tenant): string
    {
        $systemData = PromptDataContextBuilder::create()
            ->withDdls($this->buildDdlsData($tenant))
            ->withDefinitions($this->buildDefinitionsData($tenant))
            ->build();

        $systemView = new InitialPromptView($systemData);
        $datasource = $tenant->primaryDatasource();
        $systemView->setTimezoneData($tenant->timezone ?? 'UTC', $datasource->timezone ?? 'UTC');
        if ($datasource) {
            $systemView->setDatabaseDriver($this->connector->getDriver($datasource));
        }

        return $systemView->renderSystem();
    }

    /**
     * Build DDLs data for the view
     */
    private function buildDdlsData(Tenant $tenant): array
    {
        $tables = $this->fetchPriorityTables($tenant);

        return $this->mapTablesToDdlData($tables);
    }

    /**
     * Fetch priority tables for tenant, limited by max_priority_tables setting.
     */
    private function fetchPriorityTables(Tenant $tenant)
    {
        $maxPriorityTables = (int) GeneralSetting::resolve(SettingEnum::MAX_PRIORITY_TABLES)->value;
        $priorityThreshold = config('llm.ddl_context.priority_threshold', 0);

        return Table::where('tenant_id', $tenant->id)
            ->where('priority', '>', $priorityThreshold)
            ->orderBy('priority', 'desc')
            ->orderBy('name')
            ->limit($maxPriorityTables)
            ->get();
    }

    /**
     * Map tables to DDL data array
     *
     * @return array<int, array{table: string, row_count: ?int, size_mb: ?float, ddl: string}>
     */
    private function mapTablesToDdlData($tables): array
    {
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
        $definitions = $this->fetchDefinitions($tenant);

        return $this->mapDefinitionsToData($definitions);
    }

    /**
     * Fetch definitions for tenant
     */
    private function fetchDefinitions(Tenant $tenant)
    {
        return Definition::where('tenant_id', $tenant->id)
            ->orderBy('priority', 'desc')
            ->orderBy('subject')
            ->get();
    }

    /**
     * Map definitions to data array
     *
     * @return array<int, array{subject: string, definition: string}>
     */
    private function mapDefinitionsToData($definitions): array
    {
        return $definitions->map(function ($definition) {
            return [
                'subject' => $definition->subject,
                'definition' => $definition->definition,
            ];
        })->toArray();
    }

    /**
     * Build tables available data for the view
     */
    private function buildTablesAvailableData(Tenant $tenant): array
    {
        return $this->fetchNonPriorityTableNames($tenant);
    }

    /**
     * Fetch tables not included in the DDL section (non-priority + overflow priority tables).
     *
     * @return array<int, array{name: string, row_count: ?int, size_mb: ?float}>
     */
    private function fetchNonPriorityTableNames(Tenant $tenant): array
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

    /**
     * Build definitions context from thread-specific definitions.
     *
     * @param  int  $threadId  The thread ID to get definitions for
     * @return array The definitions data
     */
    private function buildThreadDefinitionsData(int $threadId): array
    {
        $definitions = $this->fetchThreadDefinitions($threadId);

        return $this->mapDefinitionsToData($definitions);
    }

    /**
     * Fetch thread-specific definitions
     */
    private function fetchThreadDefinitions(int $threadId)
    {
        return Definition::where('thread_id', $threadId)
            ->orderBy('priority', 'desc')
            ->orderBy('subject')
            ->get();
    }

    /**
     * Build user content by stripping Slack mention
     *
     * @param  string  $userQuery  The original user query
     * @return string The processed user content
     */
    private function buildUserContent(string $userQuery): string
    {
        // Strip Slack mention pattern: <@U01233456>
        $strippedQuery = preg_replace('/<@[A-Z0-9]+>/', '', $userQuery);

        // Trim whitespace
        return trim($strippedQuery);
    }

    /**
     * Build definitions context from tenant definitions.
     *
     * @param  Tenant  $tenant  The tenant to get definitions for
     * @return string|null The definitions context, or null if no definitions found
     */
    public function buildDefinitionsContext(Tenant $tenant): ?string
    {
        $definitions = Definition::where('tenant_id', $tenant->id)
            ->orderBy('priority', 'desc')
            ->orderBy('subject')
            ->get();

        if ($definitions->isEmpty()) {
            return null;
        }

        $definitionLines = [];
        foreach ($definitions as $definition) {
            $definitionLines[] = $definition->subject.' => '.$definition->definition;
        }

        return "## Definitions\n\n".implode("\n", $definitionLines);
    }

    /**
     * Build recent queries context from the last 5 completed queries for the tenant
     *
     * @param  Tenant  $tenant  The tenant to get recent queries for
     * @return string|null The recent queries context, or null if no recent queries found
     */
    public function buildRecentQueriesContext(Tenant $tenant): ?string
    {
        if (! config('llm.include_recent_queries', true)) {
            return null;
        }

        $recentQueries = $this->fetchRecentQueries($tenant);

        if ($recentQueries->isEmpty()) {
            return null;
        }

        $queryLines = $this->buildQueryLinesWithinSizeLimit($recentQueries);

        if (empty($queryLines)) {
            return null;
        }

        return "## Recent queries (last 5)\n\n".implode("\n\n", $queryLines);
    }

    /**
     * Fetch recent completed queries
     */
    private function fetchRecentQueries(Tenant $tenant)
    {
        return Query::where('tenant_id', $tenant->id)
            ->where('status', QueryStatus::DONE->value)
            ->where('score', '>=', 0)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function buildQueryLinesWithinSizeLimit($recentQueries): array
    {
        $queryLines = [];
        $totalSize = 0;
        $maxSize = config('llm.ddl_context.max_size_bytes', 200000) / 4;

        foreach ($recentQueries as $recentQuery) {
            $question = $recentQuery->raw_text ?? '';
            $sql = $recentQuery->sql_text ?? '';
            $parameters = $recentQuery->parameters ?? [];

            if (empty($question) || empty($sql)) {
                continue;
            }

            $queryBlock = $this->formatQueryBlock($question, $sql, $parameters);
            $blockSize = strlen($queryBlock);

            if ($totalSize + $blockSize > $maxSize) {
                break;
            }

            $queryLines[] = $queryBlock;
            $totalSize += $blockSize;
        }

        return $queryLines;
    }

    /**
     * Format a single query block
     */
    private function formatQueryBlock(string $question, string $sql, mixed $parameters): string
    {
        $queryBlock = "question: {$question}\nsql: {$sql}";

        if ($parameters) {
            $paramString = is_array($parameters) ? json_encode($parameters) : (string) $parameters;
            $queryBlock .= "\nparameters: ".$paramString;
        }

        return $queryBlock;
    }

    /**
     * Build other tables context from non-priority tables for the tenant
     *
     * @param  Tenant  $tenant  The tenant to get other tables for
     * @return string|null The other tables context, or null if no other tables found
     */
    public function buildOtherTablesContext(Tenant $tenant): ?string
    {
        $otherTables = Table::where('tenant_id', $tenant->id)
            ->where('priority', 0)
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        if (empty($otherTables)) {
            return null;
        }

        $tableList = implode(', ', $otherTables);

        return "## Other tables available (no DDL included) — call `fetch_table_ddls` to load\n\n{$tableList}";
    }
}
