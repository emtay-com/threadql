<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\LlmProvider;

use App\Models\LlmProvider;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateLlmProviderTest extends TestCase
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
        $provider = LlmProvider::factory()->forTenant($tenant)->create();

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", []);

        $response->assertStatus(401);
    }

    public function test_it_updates_an_llm_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Old Name',
            'adapter' => 'openai',
            'url' => 'https://old-url.com',
            'model_name' => 'gpt-3.5-turbo',
            'api_key' => 'old-key',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Updated Name',
                'adapter' => 'anthropic',
                'url' => 'https://api.anthropic.com',
                'model_name' => 'claude-3-opus',
            ]);

        $response->assertStatus(200);

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
        $this->assertEquals($provider->id, $data['id']);
        $this->assertEquals('Updated Name', $data['name']);
        $this->assertEquals('anthropic', $data['adapter']);
        $this->assertEquals('https://api.anthropic.com', $data['url']);
        $this->assertEquals('claude-3-opus', $data['model']);
        $this->assertTrue($data['has_api_key']);

        $provider->refresh();
        $this->assertEquals('Updated Name', $provider->name);
        $this->assertEquals('anthropic', $provider->adapter);
        $this->assertEquals('old-key', $provider->api_key);
    }

    public function test_it_updates_enabled_and_sort(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Test Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'enabled' => true,
            'sort' => 0,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Test Provider',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
                'enabled' => false,
                'sort' => 3,
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['enabled']);
        $this->assertEquals(3, $data['sort']);

        $provider->refresh();
        $this->assertFalse($provider->enabled);
        $this->assertEquals(3, $provider->sort);
    }

    public function test_it_returns_403_for_wrong_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($otherTenant)->create([
            'name' => 'Other Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Stolen Provider',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
            ]);

        $response->assertStatus(404);
    }

    public function test_it_updates_api_key_when_provided(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Test Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'api_key' => 'old-api-key',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Test Provider',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
                'api_key' => 'new-api-key',
            ]);

        $response->assertStatus(200);

        $provider->refresh();
        $this->assertEquals('new-api-key', $provider->api_key);
    }

    public function test_it_can_clear_api_key(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Test Provider',
            'adapter' => 'ollama',
            'model_name' => 'llama2',
            'api_key' => 'existing-key',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Test Provider',
                'adapter' => 'ollama',
                'url' => 'http://localhost:11434',
                'model_name' => 'llama2',
                'api_key' => null,
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertFalse($data['has_api_key']);

        $provider->refresh();
        $this->assertNull($provider->api_key);
    }

    public function test_it_updates_options(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'OpenAI Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'options' => [
                'organization' => 'org-old',
            ],
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'OpenAI Provider',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
                'options' => [
                    'organization' => 'org-new',
                    'project' => 'proj-123',
                ],
            ]);

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals([
            'organization' => 'org-new',
            'project' => 'proj-123',
        ], $data['options']);

        $provider->refresh();
        $this->assertEquals([
            'organization' => 'org-new',
            'project' => 'proj-123',
        ], $provider->options);
    }

    public function test_it_clears_options_when_set_to_null(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Provider with options',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'options' => [
                'organization' => 'org-123',
            ],
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Provider with options',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
                'options' => null,
            ]);

        $response->assertStatus(200);

        $provider->refresh();
        $this->assertNull($provider->options);
    }

    public function test_it_preserves_options_when_not_sent(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'options' => [
                'organization' => 'org-keep',
            ],
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", [
                'name' => 'Provider Updated',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
            ]);

        $response->assertStatus(200);

        $provider->refresh();
        $this->assertEquals([
            'organization' => 'org-keep',
        ], $provider->options);
    }

    public function test_it_validates_required_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'adapter', 'model_name']);
    }

    public function test_it_returns_404_for_non_existent_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/llm-providers/99999", [
                'name' => 'Test',
                'adapter' => 'openai',
                'url' => null,
                'model_name' => 'gpt-4',
            ]);

        $response->assertStatus(404);
    }
}
