<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\SlackUser;

use App\Models\MasterAdmin;
use App\Models\SlackUser;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RestoreSlackUserTest extends TestCase
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
        $slackUser = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);

        $response = $this->patchJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(401);
    }

    public function test_it_restores_slack_user_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);

        $slackUserId = $slackUser->id;

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $this->assertNotNull($slackUser->deleted_at);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(204);
        $response->assertNoContent();

        $this->assertDatabaseHas('slack_users', [
            'id' => $slackUserId,
            'deleted_at' => null,
        ]);
    }

    public function test_it_returns_404_for_nonexistent_slack_user(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/slack-users/99999");

        $response->assertStatus(404);
    }

    public function test_it_returns_404_for_slack_user_of_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant2->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant1->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(404);

        $this->assertNotNull(SlackUser::withTrashed()->find($slackUser->id)->deleted_at);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/admin/tenants/99999/slack-users/1');

        $response->assertStatus(404);
    }

    public function test_it_only_restores_specified_slack_user(): void
    {
        $tenant = Tenant::factory()->create();
        $user1 = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);
        $user2 = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/slack-users/{$user1->id}");

        $response->assertStatus(204);

        $this->assertNull(SlackUser::find($user1->id)->deleted_at);
        $this->assertNotNull(SlackUser::withTrashed()->find($user2->id)->deleted_at);
    }

    public function test_it_handles_restoring_non_deleted_slack_user(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}");

        $response->assertStatus(204);

        $this->assertNull(SlackUser::find($slackUser->id)->deleted_at);
    }
}
