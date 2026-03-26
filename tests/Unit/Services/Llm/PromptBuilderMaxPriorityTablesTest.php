<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Enums\QueryStatus;
use App\Enums\SettingEnum;
use App\Models\Datasource;
use App\Models\GeneralSetting;
use App\Models\Query;
use App\Models\Table;
use App\Models\Tenant;
use App\Models\Thread;
use App\Services\Llm\PromptBuilder;
use Tests\TestCase;

class PromptBuilderMaxPriorityTablesTest extends TestCase
{
    private PromptBuilder $promptBuilder;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promptBuilder = new PromptBuilder;
        $this->tenant = Tenant::factory()->create();

        Datasource::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_initial_prompt_includes_only_top_n_priority_tables_as_ddl(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_PRIORITY_TABLES,
            'value' => '2',
        ]);

        // Create 4 priority tables with different priorities
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'high_priority_table',
            'priority' => 10,
            'ddl_sql' => 'CREATE TABLE high_priority_table (id INT);',
        ]);
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'medium_priority_table',
            'priority' => 5,
            'ddl_sql' => 'CREATE TABLE medium_priority_table (id INT);',
        ]);
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'low_priority_table',
            'priority' => 3,
            'ddl_sql' => 'CREATE TABLE low_priority_table (id INT);',
        ]);
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'lowest_priority_table',
            'priority' => 1,
            'ddl_sql' => 'CREATE TABLE lowest_priority_table (id INT);',
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::RECEIVED->value,
            'raw_text' => 'Show me all users',
        ]);

        $messages = $this->promptBuilder->buildPrompt($query, $this->tenant);

        $systemContent = $messages[0]['content'];

        // Top 2 tables should have DDL included
        $this->assertStringContainsString('CREATE TABLE high_priority_table', $systemContent);
        $this->assertStringContainsString('CREATE TABLE medium_priority_table', $systemContent);

        // Overflow tables should NOT have DDL but should appear in tables available
        $this->assertStringNotContainsString('CREATE TABLE low_priority_table', $systemContent);
        $this->assertStringNotContainsString('CREATE TABLE lowest_priority_table', $systemContent);
        $this->assertStringContainsString('low_priority_table', $systemContent);
        $this->assertStringContainsString('lowest_priority_table', $systemContent);
    }

    public function test_overflow_priority_tables_appear_in_tables_available_section(): void
    {
        GeneralSetting::create([
            'setting' => SettingEnum::MAX_PRIORITY_TABLES,
            'value' => '1',
        ]);

        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'top_table',
            'priority' => 10,
            'ddl_sql' => 'CREATE TABLE top_table (id INT);',
        ]);
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'overflow_table',
            'priority' => 5,
            'ddl_sql' => 'CREATE TABLE overflow_table (id INT);',
        ]);
        Table::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'zero_priority_table',
            'priority' => 0,
            'ddl_sql' => 'CREATE TABLE zero_priority_table (id INT);',
        ]);

        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::RECEIVED->value,
            'raw_text' => 'Show me data',
        ]);

        $messages = $this->promptBuilder->buildPrompt($query, $this->tenant);

        $systemContent = $messages[0]['content'];

        // Only top_table gets DDL
        $this->assertStringContainsString('CREATE TABLE top_table', $systemContent);
        $this->assertStringNotContainsString('CREATE TABLE overflow_table', $systemContent);

        // Both overflow and zero-priority appear in "tables available"
        $this->assertStringContainsString('Other tables available', $systemContent);
        $this->assertStringContainsString('overflow_table', $systemContent);
        $this->assertStringContainsString('zero_priority_table', $systemContent);
    }

    public function test_uses_default_setting_when_no_general_setting_exists(): void
    {
        // No GeneralSetting created — should use config default of 20
        // Create 3 priority tables (all under default limit of 20)
        for ($i = 1; $i <= 3; $i++) {
            Table::factory()->create([
                'tenant_id' => $this->tenant->id,
                'name' => "table_{$i}",
                'priority' => $i,
                'ddl_sql' => "CREATE TABLE table_{$i} (id INT);",
            ]);
        }

        $thread = Thread::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $query = Query::factory()->create([
            'tenant_id' => $this->tenant->id,
            'thread_id' => $thread->id,
            'status' => QueryStatus::RECEIVED->value,
            'raw_text' => 'Show me data',
        ]);

        $messages = $this->promptBuilder->buildPrompt($query, $this->tenant);

        $systemContent = $messages[0]['content'];

        // All 3 should have DDL since default is 20
        $this->assertStringContainsString('CREATE TABLE table_1', $systemContent);
        $this->assertStringContainsString('CREATE TABLE table_2', $systemContent);
        $this->assertStringContainsString('CREATE TABLE table_3', $systemContent);
    }
}
