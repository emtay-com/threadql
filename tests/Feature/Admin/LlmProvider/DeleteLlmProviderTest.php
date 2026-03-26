<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\LlmProvider;

use App\Models\LlmProvider;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DeleteLlmProviderTest extends TestCase
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

        $response = $this->deleteJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}");

        $response->assertStatus(401);
    }

    public function test_it_deletes_an_llm_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->create([
            'name' => 'To Delete',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}");

        $response->assertStatus(204);

        $this->assertNull(LlmProvider::find($provider->id));
    }

    public function test_it_returns_403_for_wrong_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($otherTenant)->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/llm-providers/{$provider->id}");

        $response->assertStatus(404);

        // Provider should still exist
        $this->assertNotNull(LlmProvider::find($provider->id));
    }

    public function test_it_returns_404_for_non_existent_provider(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/llm-providers/99999");

        $response->assertStatus(404);
    }
}
