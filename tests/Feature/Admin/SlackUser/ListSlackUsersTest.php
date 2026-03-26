<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\SlackUser;

use App\Models\MasterAdmin;
use App\Models\SlackUser;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListSlackUsersTest extends TestCase
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

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/slack-users");

        $response->assertStatus(401);
    }

    public function test_it_lists_all_slack_users_for_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $user1 = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U1234567890',
            'real_name' => 'John Doe',
            'display_name' => 'johndoe',
            'approved' => true,
        ]);

        $user2 = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U0987654321',
            'real_name' => 'Jane Smith',
            'display_name' => 'janesmith',
            'approved' => false,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/slack-users");

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'tenant_id',
                    'slack_user_id',
                    'real_name',
                    'display_name',
                    'avatar_url',
                    'approved',
                    'created_at',
                    'deleted_at',
                ],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals(2, $response->json('meta.total'));

        $u1Data = collect($data)
            ->firstWhere('id', $user1->id);
        $this->assertNotNull($u1Data);
        $this->assertEquals('U1234567890', $u1Data['slack_user_id']);
        $this->assertEquals('John Doe', $u1Data['real_name']);
        $this->assertTrue($u1Data['approved']);

        $u2Data = collect($data)
            ->firstWhere('id', $user2->id);
        $this->assertNotNull($u2Data);
        $this->assertEquals('U0987654321', $u2Data['slack_user_id']);
        $this->assertFalse($u2Data['approved']);
    }

    public function test_it_only_returns_slack_users_for_requested_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        SlackUser::factory()->create([
            'tenant_id' => $tenant1->id,
            'slack_user_id' => 'U1111111111',
        ]);

        SlackUser::factory()->create([
            'tenant_id' => $tenant2->id,
            'slack_user_id' => 'U2222222222',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant1->id}/slack-users");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('U1111111111', $data[0]['slack_user_id']);
    }

    public function test_it_orders_slack_users_by_created_at_desc(): void
    {
        $tenant = Tenant::factory()->create();

        $older = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U_OLDER',
        ]);
        $this->travel(1)
            ->hours();
        $newer = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U_NEWER',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/slack-users");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals($newer->id, $data[0]['id']);
        $this->assertEquals($older->id, $data[1]['id']);
    }

    public function test_it_includes_soft_deleted_users(): void
    {
        $tenant = Tenant::factory()->create();

        SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U_ACTIVE',
        ]);

        SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U_DELETED',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/slack-users");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $deletedUser = collect($data)
            ->firstWhere('slack_user_id', 'U_DELETED');
        $this->assertNotNull($deletedUser['deleted_at']);

        $activeUser = collect($data)
            ->firstWhere('slack_user_id', 'U_ACTIVE');
        $this->assertNull($activeUser['deleted_at']);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/slack-users');

        $response->assertStatus(404);
    }

    public function test_it_returns_empty_array_when_no_slack_users(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/slack-users");

        $response->assertStatus(200);
        $response->assertJson([
            'data' => [],
            'meta' => [
                'total' => 0,
            ],
        ]);
    }
}
