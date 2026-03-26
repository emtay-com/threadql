<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\SlackUser;

use App\Models\MasterAdmin;
use App\Models\SlackUser;
use App\Models\Tenant;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateSlackUserTest extends TestCase
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

        $response = $this->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
            'approved' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_it_updates_approved_and_returns_204(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'approved' => false,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'approved' => true,
            ]);

        $response->assertStatus(204);
        $response->assertNoContent();

        $slackUser->refresh();
        $this->assertTrue($slackUser->approved);
    }

    public function test_it_updates_real_name(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'real_name' => 'Old Name',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'real_name' => 'New Name',
            ]);

        $response->assertStatus(204);

        $slackUser->refresh();
        $this->assertEquals('New Name', $slackUser->real_name);
    }

    public function test_it_updates_display_name(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'display_name' => 'oldname',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'display_name' => 'newname',
            ]);

        $response->assertStatus(204);

        $slackUser->refresh();
        $this->assertEquals('newname', $slackUser->display_name);
    }

    public function test_it_validates_approved_is_boolean(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'approved' => 'not-a-boolean',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['approved']);
    }

    public function test_it_validates_real_name_max_length(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'real_name' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['real_name']);
    }

    public function test_it_returns_404_for_nonexistent_slack_user(): void
    {
        $tenant = Tenant::factory()->create();

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/99999", [
                'approved' => true,
            ]);

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
            ->putJson("/api/admin/tenants/{$tenant1->id}/slack-users/{$slackUser->id}", [
                'approved' => true,
            ]);

        $response->assertStatus(404);
    }

    public function test_it_only_updates_allowed_fields(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U_ORIGINAL',
            'real_name' => 'Original Name',
            'approved' => false,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'approved' => true,
                'slack_user_id' => 'U_HACKED',
                'avatar_url' => 'https://evil.com/avatar.jpg',
            ]);

        $response->assertStatus(204);

        $slackUser->refresh();
        $this->assertTrue($slackUser->approved);
        $this->assertEquals('U_ORIGINAL', $slackUser->slack_user_id);
        $this->assertEquals('Original Name', $slackUser->real_name);
    }

    public function test_it_returns_404_for_soft_deleted_slack_user(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->trashed()->create([
            'tenant_id' => $tenant->id,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/admin/tenants/{$tenant->id}/slack-users/{$slackUser->id}", [
                'approved' => true,
            ]);

        $response->assertStatus(404);
    }
}
