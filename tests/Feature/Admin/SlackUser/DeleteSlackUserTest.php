<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\SlackUser;

use App\Models\MasterAdmin;
use App\Models\SlackUser;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class DeleteSlackUserTest extends TestCase
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
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->deleteJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(401);
    }

    public function test_it_deletes_slack_user_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $slackUserId = $slackUser->id;

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(204);
        $response->assertNoContent();

        $this->assertSoftDeleted('slack_users', [
            'id' => $slackUserId,
        ]);
    }

    public function test_it_returns_404_for_nonexistent_slack_user(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/slack-users/99999");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_slack_user_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant1->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('slack_users', [
            'id' => $slackUser->id,
            'deleted_at' => null,
        ]);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/admin/tenants/99999/slack-users/1');

        $response->assertStatus(404);
    }

    public function test_it_only_deletes_specified_slack_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user1 = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $user2 = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/admin/tenants/{$tenant->id}/slack-users/{$user1->id}");

        $response->assertStatus(204);

        $this->assertSoftDeleted('slack_users', [
            'id' => $user1->id,
        ]);

        $this->assertDatabaseHas('slack_users', [
            'id' => $user2->id,
            'deleted_at' => null,
        ]);
    }
}
