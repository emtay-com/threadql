<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm\Builders;

use App\Services\Llm\Builders\PromptDataContextBuilder;
use Tests\TestCase;

final class PromptDataContextBuilderTest extends TestCase
{
    public function test_builds_empty_data_by_default(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->build();

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_builds_data_with_query_id(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withQueryId(123)
            ->build();

        $this->assertEquals(123, $data['query_id']);
    }

    public function test_builds_data_with_user_query_text(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withUserQueryText('What is the revenue?')
            ->build();

        $this->assertEquals('What is the revenue?', $data['user_query_text']);
    }

    public function test_builds_data_with_ddls(): void
    {
        $builder = new PromptDataContextBuilder;

        $ddls = [
            [
                'table' => 'users',
                'ddl' => 'CREATE TABLE users...',
            ],
            [
                'table' => 'orders',
                'ddl' => 'CREATE TABLE orders...',
            ],
        ];

        $data = $builder->withDdls($ddls)
            ->build();

        $this->assertEquals($ddls, $data['ddls']);
    }

    public function test_does_not_add_ddls_when_empty(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withDdls([])->build();

        $this->assertArrayNotHasKey('ddls', $data);
    }

    public function test_builds_data_with_definitions(): void
    {
        $builder = new PromptDataContextBuilder;

        $definitions = [
            [
                'subject' => 'ARR',
                'definition' => 'Annual Recurring Revenue',
            ],
            [
                'subject' => 'MRR',
                'definition' => 'Monthly Recurring Revenue',
            ],
        ];

        $data = $builder->withDefinitions($definitions)
            ->build();

        $this->assertEquals($definitions, $data['definitions']);
    }

    public function test_does_not_add_definitions_when_empty(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withDefinitions([])->build();

        $this->assertArrayNotHasKey('definitions', $data);
    }

    public function test_builds_data_with_tables_available(): void
    {
        $builder = new PromptDataContextBuilder;

        $tables = ['products', 'categories', 'inventory'];

        $data = $builder->withTablesAvailable($tables)
            ->build();

        $this->assertEquals($tables, $data['tables_available']);
    }

    public function test_does_not_add_tables_available_when_empty(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withTablesAvailable([])->build();

        $this->assertArrayNotHasKey('tables_available', $data);
    }

    public function test_builds_data_with_timezone_data(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder->withTimezoneData('America/New_York', 'UTC')
            ->build();

        $this->assertEquals('America/New_York', $data['tenant_timezone']);
        $this->assertEquals('UTC', $data['datasource_timezone']);
    }

    public function test_fluent_interface_chains_multiple_methods(): void
    {
        $builder = new PromptDataContextBuilder;

        $data = $builder
            ->withQueryId(456)
            ->withUserQueryText('Show me sales')
            ->withDdls([[
                'table' => 'sales',
                'ddl' => 'CREATE TABLE...',
            ]])
            ->withDefinitions([[
                'subject' => 'Revenue',
                'definition' => 'Total income',
            ]])
            ->withTablesAvailable(['customers', 'invoices'])
            ->withTimezoneData('Europe/London', 'America/Chicago')
            ->build();

        $this->assertEquals(456, $data['query_id']);
        $this->assertEquals('Show me sales', $data['user_query_text']);
        $this->assertCount(1, $data['ddls']);
        $this->assertCount(1, $data['definitions']);
        $this->assertCount(2, $data['tables_available']);
        $this->assertEquals('Europe/London', $data['tenant_timezone']);
        $this->assertEquals('America/Chicago', $data['datasource_timezone']);
    }

    public function test_create_static_method_returns_new_instance(): void
    {
        $builder = PromptDataContextBuilder::create();

        $this->assertInstanceOf(PromptDataContextBuilder::class, $builder);
    }

    public function test_reset_clears_all_data(): void
    {
        $builder = new PromptDataContextBuilder;

        $builder
            ->withQueryId(789)
            ->withUserQueryText('Test query')
            ->reset();

        $data = $builder->build();

        $this->assertEmpty($data);
    }

    public function test_reset_allows_reuse_of_builder(): void
    {
        $builder = new PromptDataContextBuilder;

        $data1 = $builder->withQueryId(100)
            ->build();
        $this->assertEquals(100, $data1['query_id']);

        $builder->reset();

        $data2 = $builder->withQueryId(200)
            ->build();
        $this->assertEquals(200, $data2['query_id']);
        $this->assertCount(1, $data2); // Should only have query_id
    }
}
