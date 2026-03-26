<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\User;

use App\Enums\UserLevel;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ManageUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    public function test_master_can_create_user_with_explicit_email(): void
    {
        $tenant = Tenant::factory()->create();
        $masterToken = JWTAuth::fromUser(MasterAdmin::instance());

        $response = $this->withHeader('Authorization', "Bearer {$masterToken}")
            ->postJson('/api/admin/users', [
                'username' => 'tenant_admin',
                'email' => 'tenant.admin@example.com',
                'password' => 'password-1234',
                'tenant_id' => $tenant->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'username', 'email', 'level', 'tenant_id', 'tenant_name', 'created_at', 'updated_at'],
        ]);
        $response->assertJson([
            'data' => [
                'username' => 'tenant_admin',
                'email' => 'tenant.admin@example.com',
                'level' => UserLevel::TENANT->value,
                'tenant_id' => $tenant->id,
            ],
        ]);

        $userId = (int) $response->json('data.id');
        $user = User::find($userId);

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password-1234', (string) $user->password));
        $this->assertFalse(Hash::needsRehash((string) $user->password, [
            'rounds' => 12,
        ]));
    }

    public function test_master_can_list_update_and_delete_users(): void
    {
        $tenantA = Tenant::factory()->create([
            'name' => 'Tenant A',
        ]);
        $tenantB = Tenant::factory()->create([
            'name' => 'Tenant B',
        ]);

        $user = User::factory()->forTenant($tenantA)->create([
            'username' => 'alpha_user',
            'email' => 'alpha@example.com',
            'level' => UserLevel::TENANT->value,
        ]);

        $masterToken = JWTAuth::fromUser(MasterAdmin::instance());

        $list = $this->withHeader('Authorization', "Bearer {$masterToken}")
            ->getJson('/api/admin/users');

        $list->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $list->json('meta.total'));

        $update = $this->withHeader('Authorization', "Bearer {$masterToken}")
            ->putJson("/api/admin/users/{$user->id}", [
                'username' => 'beta_user',
                'email' => 'beta@example.com',
                'tenant_id' => $tenantB->id,
                'password' => 'new-password-123',
            ]);

        $update->assertStatus(200);
        $update->assertJson([
            'data' => [
                'id' => $user->id,
                'username' => 'beta_user',
                'email' => 'beta@example.com',
                'tenant_id' => $tenantB->id,
                'tenant_name' => 'Tenant B',
            ],
        ]);

        $user->refresh();
        $this->assertEquals('beta_user', $user->username);
        $this->assertEquals('beta@example.com', $user->email);
        $this->assertEquals($tenantB->id, $user->tenant_id);
        $this->assertTrue(Hash::check('new-password-123', (string) $user->password));

        $delete = $this->withHeader('Authorization', "Bearer {$masterToken}")
            ->deleteJson("/api/admin/users/{$user->id}");

        $delete->assertStatus(204);
        $this->assertNull(User::find($user->id));
    }

    public function test_tenant_user_cannot_manage_users(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantUser = User::factory()->forTenant($tenant)->create([
            'username' => 'tenant_user',
            'email' => 'tenant.user@example.com',
            'level' => UserLevel::TENANT->value,
        ]);

        $token = JWTAuth::fromUser($tenantUser);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/users')
            ->assertStatus(403);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/admin/users', [
                'username' => 'other_user',
                'email' => 'other.user@example.com',
                'password' => 'password-1234',
                'tenant_id' => $tenant->id,
            ])
            ->assertStatus(403);
    }
}
