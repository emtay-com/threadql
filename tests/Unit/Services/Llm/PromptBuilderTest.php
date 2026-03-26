<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Models\Definition;
use App\Models\Tenant;
use App\Services\Llm\PromptBuilder;
use Tests\TestCase;

class PromptBuilderTest extends TestCase
{
    private PromptBuilder $promptBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->promptBuilder = new PromptBuilder();
    }

    public function test_build_definitions_context_returns_null_when_no_definitions(): void
    {
        $tenant = Tenant::factory()->create();

        $result = $this->promptBuilder->buildDefinitionsContext($tenant);

        $this->assertNull($result);
    }

    public function test_build_definitions_context_formats_definitions_correctly(): void
    {
        $tenant = Tenant::factory()->create();

        Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'user123',
            'priority' => 1,
            'subject' => 'active members',
            'definition' => 'member with status 1',
        ]);

        Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'user123',
            'priority' => 2,
            'subject' => 'premium users',
            'definition' => 'users with subscription level gold or platinum',
        ]);

        $result = $this->promptBuilder->buildDefinitionsContext($tenant);

        $expected = "## Definitions\n\n".
            "premium users => users with subscription level gold or platinum\n".
            'active members => member with status 1';

        $this->assertEquals($expected, $result);
    }

    public function test_build_definitions_context_orders_by_priority_then_subject(): void
    {
        $tenant = Tenant::factory()->create();

        Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'user123',
            'priority' => 1,
            'subject' => 'zebra term',
            'definition' => 'definition for zebra',
        ]);

        Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'user123',
            'priority' => 2,
            'subject' => 'alpha term',
            'definition' => 'definition for alpha',
        ]);

        Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'user123',
            'priority' => 1,
            'subject' => 'beta term',
            'definition' => 'definition for beta',
        ]);

        $result = $this->promptBuilder->buildDefinitionsContext($tenant);

        $expected = "## Definitions\n\n".
            "alpha term => definition for alpha\n".
            "beta term => definition for beta\n".
            'zebra term => definition for zebra';

        $this->assertEquals($expected, $result);
    }

    public function test_build_definitions_context_only_includes_tenant_definitions(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        Definition::create([
            'tenant_id' => $tenant1->id,
            'user_id' => 'user123',
            'priority' => 1,
            'subject' => 'tenant1 term',
            'definition' => 'definition for tenant1',
        ]);

        Definition::create([
            'tenant_id' => $tenant2->id,
            'user_id' => 'user123',
            'priority' => 1,
            'subject' => 'tenant2 term',
            'definition' => 'definition for tenant2',
        ]);

        $result = $this->promptBuilder->buildDefinitionsContext($tenant1);

        $expected = "## Definitions\n\n".
            'tenant1 term => definition for tenant1';

        $this->assertEquals($expected, $result);
    }
}
