<?php

declare(strict_types=1);

namespace Tests\Unit\CommandHandlers;

use App\Command\TestLlmProviderConnectionCommand;
use App\CommandHandlers\TestLlmProviderConnectionHandler;
use App\Models\LlmProvider;
use App\Models\Tenant;
use App\Services\Llm\ProviderOptionsResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TestLlmProviderConnectionHandlerTest extends TestCase
{
    private TestLlmProviderConnectionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new TestLlmProviderConnectionHandler(
            new ProviderOptionsResolver(),
            app(HttpFactory::class),
        );
    }

    #[Test]
    public function it_returns_success_for_openai_compatible_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant)->create();

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->connected);
        $this->assertNull($response->errorMessage);
        $this->assertEmpty($response->getErrors());
    }

    #[Test]
    public function it_returns_failure_on_401_unauthorized(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant)->create();

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'error' => 'invalid_api_key',
            ], 401),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertFalse($response->isSuccess());
        $this->assertFalse($response->connected);
        $this->assertStringContainsString('401', $response->errorMessage);
    }

    #[Test]
    public function it_returns_failure_on_connection_error(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->create([
            'tenant_id' => $tenant->id,
            'adapter' => 'openai',
            'url' => 'https://unreachable.example.com/v1',
        ]);

        Http::fake([
            'unreachable.example.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
                'Connection refused'
            ),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertFalse($response->isSuccess());
        $this->assertNotEmpty($response->errorMessage);
    }

    #[Test]
    public function it_uses_x_api_key_header_and_anthropic_version_for_anthropic(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->anthropic()->forTenant($tenant)->create([
            'api_key' => 'sk-ant-test-key',
        ]);

        Http::fake([
            'api.anthropic.com/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'sk-ant-test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && ! $request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function it_uses_custom_anthropic_version_from_options(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->anthropic()->forTenant($tenant)
            ->withOptions([
                'version' => '2024-01-01',
            ])
            ->create();

        Http::fake([
            'api.anthropic.com/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        ($this->handler)($command);

        Http::assertSent(function ($request) {
            return $request->hasHeader('anthropic-version', '2024-01-01');
        });
    }

    #[Test]
    public function it_uses_api_tags_endpoint_for_ollama(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->ollama()->forTenant($tenant)->create();

        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/tags'));
    }

    #[Test]
    public function it_uses_x_goog_api_key_header_for_gemini(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->create([
            'tenant_id' => $tenant->id,
            'adapter' => 'gemini',
            'url' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'model_name' => 'gemini-1.5-flash',
            'api_key' => 'test-gemini-key',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'models' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
        Http::assertSent(function ($request) {
            return $request->hasHeader('x-goog-api-key', 'test-gemini-key')
                && ! $request->hasHeader('Authorization');
        });
    }

    #[Test]
    public function it_uses_default_url_when_provider_has_no_url(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->create([
            'tenant_id' => $tenant->id,
            'adapter' => 'openai',
            'url' => null,
            'model_name' => 'gpt-4',
            'api_key' => 'test-key',
        ]);

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        $response = ($this->handler)($command);

        $this->assertTrue($response->isSuccess());
    }

    #[Test]
    public function it_sends_bearer_token_for_openai_compatible_providers(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->create([
            'tenant_id' => $tenant->id,
            'adapter' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'model_name' => 'gpt-4',
            'api_key' => 'sk-test-key-123',
        ]);

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: $provider->id);

        ($this->handler)($command);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer sk-test-key-123')
                && ! $request->hasHeader('x-api-key');
        });
    }

    #[Test]
    public function it_throws_exception_when_provider_not_found(): void
    {
        $tenant = Tenant::factory()->create();

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant->id, llmProviderId: 99999);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        ($this->handler)($command);
    }

    #[Test]
    public function it_throws_exception_when_provider_belongs_to_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant2)->create();

        $command = new TestLlmProviderConnectionCommand(tenantId: $tenant1->id, llmProviderId: $provider->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        ($this->handler)($command);
    }
}
