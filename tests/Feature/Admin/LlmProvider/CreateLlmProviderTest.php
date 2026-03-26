<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\LlmProvider;

use App\Models\LlmProvider;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CreateLlmProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", []);

        $response->assertStatus(401);
    }

    public function test_it_creates_an_llm_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => 'OpenAI GPT-4',
                'adapter' => 'openai',
                'url' => 'https://api.openai.com/v1',
                'model_name' => 'gpt-4-turbo',
                'api_key' => 'sk-test-api-key-12345',
            ]);

        $response->assertStatus(201);

        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'adapter',
                'url',
                'model',
                'has_api_key',
                'options',
                'enabled',
                'sort',
                'created_at',
                'updated_at',
            ],
        ]);

        $data = $response->json('data');
        $this->assertEquals('OpenAI GPT-4', $data['name']);
        $this->assertEquals('openai', $data['adapter']);
        $this->assertEquals('https://api.openai.com/v1', $data['url']);
        $this->assertEquals('gpt-4-turbo', $data['model']);
        $this->assertTrue($data['has_api_key']);
        $this->assertTrue($data['enabled']);
        $this->assertEquals(0, $data['sort']);

        // Verify provider exists in database with correct tenant_id
        $provider = LlmProvider::find($data['id']);
        $this->assertNotNull($provider);
        $this->assertEquals('OpenAI GPT-4', $provider->name);
        $this->assertEquals($tenant->id, $provider->tenant_id);
        $this->assertEquals('sk-test-api-key-12345', $provider->api_key);
    }

    public function test_it_creates_provider_with_enabled_and_auto_sort(): void
    {
        $tenant = Tenant::factory()->create();
        LlmProvider::factory()->forTenant($tenant)->create([
            'sort' => 0,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => 'Secondary Provider',
                'adapter' => 'anthropic',
                'url' => 'https://api.anthropic.com',
                'model_name' => 'claude-3-sonnet',
                'enabled' => false,
                'sort' => 999,
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertFalse($data['enabled']);
        $this->assertEquals(1, $data['sort']);
    }

    public function test_it_creates_provider_without_api_key(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => 'Local Ollama',
                'adapter' => 'ollama',
                'url' => 'http://localhost:11434',
                'model_name' => 'llama2',
                'api_key' => null,
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals('Local Ollama', $data['name']);
        $this->assertFalse($data['has_api_key']);
    }

    public function test_it_validates_required_fields(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'adapter', 'model_name']);
    }

    public function test_it_creates_provider_with_options(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => 'OpenAI with Org',
                'adapter' => 'openai',
                'url' => 'https://api.openai.com/v1',
                'model_name' => 'gpt-4',
                'api_key' => 'sk-test-key',
                'options' => [
                    'organization' => 'org-123',
                    'project' => 'proj-456',
                ],
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals([
            'organization' => 'org-123',
            'project' => 'proj-456',
        ], $data['options']);

        $provider = LlmProvider::find($data['id']);
        $this->assertEquals([
            'organization' => 'org-123',
            'project' => 'proj-456',
        ], $provider->options);
    }

    public function test_it_creates_provider_with_null_options(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => 'Ollama Simple',
                'adapter' => 'ollama',
                'url' => 'http://localhost:11434',
                'model_name' => 'llama2',
                'options' => null,
            ]);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertNull($data['options']);
    }

    public function test_it_validates_string_max_length(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/admin/tenants/{$tenant->id}/llm-providers", [
                'name' => str_repeat('a', 256),
                'adapter' => 'openai',
                'model_name' => 'gpt-4',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }
}
