<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Tenant;

use App\Enums\UserLevel;
use App\Models\Tenant;
use App\Models\User;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class TenantScopeAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    public function test_tenant_user_only_sees_their_own_tenant_in_list(): void
    {
        $tenantA = Tenant::factory()->create([
            'name' => 'Tenant A',
        ]);
        Tenant::factory()->create([
            'name' => 'Tenant B',
        ]);

        $tenantUser = User::factory()->forTenant($tenantA)->create([
            'username' => 'tenant_a_admin',
            'email' => 'tenant.a@example.com',
            'level' => UserLevel::TENANT->value,
        ]);

        $token = JWTAuth::fromUser($tenantUser);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJson([
            'meta' => [
                'total' => 1,
            ],
        ]);
        $this->assertEquals($tenantA->id, $response->json('data.0.id'));
    }

    public function test_tenant_user_cannot_access_other_tenant_specific_endpoint(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $tenantUser = User::factory()->forTenant($tenantA)->create([
            'username' => 'tenant_a_admin',
            'email' => 'tenant.a@example.com',
            'level' => UserLevel::TENANT->value,
        ]);

        $token = JWTAuth::fromUser($tenantUser);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenantB->id}/manifest")
            ->assertStatus(404);
    }
}
