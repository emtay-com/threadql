<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\LlmProvider;

use App\Models\LlmProvider;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class PingLlmProviderTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $this->token = JWTAuth::fromUser($masterAdmin);
    }

    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant)->create();

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(401);
    }

    public function test_it_returns_success_when_connection_works(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant)->create();

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'connected' => true,
            ],
        ]);
    }

    public function test_it_returns_error_when_connection_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant)->create();

        Http::fake([
            'api.openai.com/v1/models' => Http::response([
                'error' => 'invalid_api_key',
            ], 401),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(422);
        $response->assertJson([
            'data' => [
                'connected' => false,
            ],
        ]);
        $response->assertJsonStructure([
            'data' => ['connected', 'error'],
        ]);
    }

    public function test_it_returns_404_for_provider_belonging_to_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $provider = LlmProvider::factory()->openai()->forTenant($tenant2)->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_nonexistent_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/99999/ping");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/admin/tenants/99999/llm-providers/1/ping');

        $response->assertStatus(404);
    }

    public function test_it_works_with_anthropic_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->anthropic()->forTenant($tenant)->create();

        Http::fake([
            'api.anthropic.com/models' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'connected' => true,
            ],
        ]);
    }

    public function test_it_works_with_ollama_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->ollama()->forTenant($tenant)->create();

        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}/ping");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [
                'connected' => true,
            ],
        ]);
    }
}
