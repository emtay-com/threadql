<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Exceptions\LlmProviderNotSetException;
use App\Models\LlmProvider;
use App\Models\Tenant;
use App\Services\Llm\LlmProviderResolver;
use InvalidArgumentException;
use Tests\TestCase;

class LlmProviderResolverTest extends TestCase
{
    private LlmProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new LlmProviderResolver();
    }

    public function test_it_resolves_first_enabled_provider_by_sort(): void
    {
        $tenant = Tenant::factory()->create();

        LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'enabled' => true,
            'sort' => 2,
        ]);

        $expected = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'anthropic',
            'model_name' => 'claude-3-sonnet',
            'enabled' => true,
            'sort' => 1,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals($expected->id, $result->id);
        $this->assertEquals('anthropic', $result->adapter);
    }

    public function test_it_skips_disabled_providers(): void
    {
        $tenant = Tenant::factory()->create();

        LlmProvider::factory()->forTenant($tenant)->disabled()->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'sort' => 0,
        ]);

        $expected = LlmProvider::factory()->forTenant($tenant)->enabled()->create([
            'adapter' => 'anthropic',
            'model_name' => 'claude-3-sonnet',
            'sort' => 1,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals($expected->id, $result->id);
    }

    public function test_it_throws_exception_when_no_enabled_provider(): void
    {
        $tenant = Tenant::factory()->create();

        LlmProvider::factory()->forTenant($tenant)->disabled()->create([
            'adapter' => 'openai',
            'sort' => 0,
        ]);

        $this->expectException(LlmProviderNotSetException::class);
        $this->expectExceptionMessage("No enabled LLM provider found for tenant {$tenant->id}");

        $this->resolver->resolve($tenant);
    }

    public function test_it_throws_exception_when_no_providers_at_all(): void
    {
        $tenant = Tenant::factory()->create();

        $this->expectException(LlmProviderNotSetException::class);

        $this->resolver->resolve($tenant);
    }

    public function test_it_throws_exception_for_unsupported_adapter(): void
    {
        $tenant = Tenant::factory()->create();

        LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'unsupported',
            'enabled' => true,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("LLM adapter 'unsupported' is not implemented yet");

        $this->resolver->resolve($tenant);
    }

    public function test_it_accepts_tenant_id_instead_of_tenant_object(): void
    {
        $tenant = Tenant::factory()->create();

        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'enabled' => true,
        ]);

        $result = $this->resolver->resolve($tenant->id);

        $this->assertEquals($provider->id, $result->id);
    }

    public function test_it_returns_provider_model_name_when_available(): void
    {
        $provider = LlmProvider::factory()->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4o-custom',
        ]);

        $modelName = $this->resolver->getModelName($provider);

        $this->assertEquals('gpt-4o-custom', $modelName);
    }

    public function test_it_falls_back_to_config_default_for_openai(): void
    {
        config([
            'llm.provider_defaults.openai.model' => 'gpt-4o-default',
        ]);

        $provider = LlmProvider::factory()->create([
            'adapter' => 'openai',
            'model_name' => '',
        ]);

        $modelName = $this->resolver->getModelName($provider);

        $this->assertEquals('gpt-4o-default', $modelName);
    }

    public function test_it_throws_exception_for_unsupported_adapter_in_get_model_name(): void
    {
        $provider = LlmProvider::factory()->create([
            'adapter' => 'unsupported',
            'model_name' => '',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("No default model for adapter 'unsupported'");

        $this->resolver->getModelName($provider);
    }

    public function test_it_uses_id_ordering_as_tiebreaker_for_same_sort(): void
    {
        $tenant = Tenant::factory()->create();

        $first = LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'enabled' => true,
            'sort' => 0,
        ]);

        LlmProvider::factory()->forTenant($tenant)->create([
            'adapter' => 'anthropic',
            'model_name' => 'claude-3-sonnet',
            'enabled' => true,
            'sort' => 0,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals($first->id, $result->id);
    }
}
