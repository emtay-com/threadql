<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\LlmProvider;

use App\Models\LlmProvider;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListLlmProvidersTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/llm-providers");

        $response->assertStatus(401);
    }

    public function test_it_lists_all_llm_providers_for_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $provider1 = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'OpenAI GPT-4',
            'adapter' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'model_name' => 'gpt-4',
            'api_key' => 'sk-test-key-1234',
            'sort' => 1,
        ]);

        $provider2 = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Anthropic Claude',
            'adapter' => 'anthropic',
            'url' => 'https://api.anthropic.com',
            'model_name' => 'claude-3-sonnet',
            'api_key' => null,
            'sort' => 0,
        ]);

        // Provider for another tenant - should not appear
        $otherTenant = Tenant::factory()->create();
        LlmProvider::factory()->forTenant($otherTenant)->create([
            'name' => 'Other',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'adapter',
                    'url',
                    'model',
                    'has_api_key',
                    'enabled',
                    'sort',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        // Should be ordered by sort, so provider2 (sort=0) comes first
        $this->assertEquals($provider2->id, $data[0]['id']);
        $this->assertEquals($provider1->id, $data[1]['id']);

        $this->assertEquals(2, $response->json('meta.total'));
    }

    public function test_it_does_not_expose_api_key(): void
    {
        $tenant = Tenant::factory()->create();
        LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'Secret Provider',
            'api_key' => 'sk-super-secret-api-key-12345',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers");

        $response->assertStatus(200);

        $data = $response->json('data');
        $providerData = $data[0];

        $this->assertArrayNotHasKey('api_key', $providerData);
        $this->assertTrue($providerData['has_api_key']);
    }

    public function test_it_returns_empty_list_when_no_providers(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/llm-providers");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ]);
    }
}
