<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Slack;

use App\Enums\TenantSettingEnum;
use App\Infrastructure\Slack\SlackUserResolver;
use App\Models\SlackUser;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Tests\TestCase;

class SlackUserResolverTest extends TestCase
{
    private SlackUserResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new SlackUserResolver;
    }

    public function test_new_user_is_approved_when_auto_approve_enabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        $slackUser = $this->resolver->findOrCreate($tenant, 'U1234567890');

        $this->assertTrue($slackUser->approved);
        $this->assertDatabaseHas('slack_users', [
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => true,
        ]);
    }

    public function test_new_user_is_not_approved_when_auto_approve_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '0',
        ]);

        $slackUser = $this->resolver->findOrCreate($tenant, 'U1234567890');

        $this->assertFalse($slackUser->approved);
        $this->assertDatabaseHas('slack_users', [
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => false,
        ]);
    }

    public function test_new_user_is_approved_by_default_when_no_setting_exists(): void
    {
        $tenant = Tenant::factory()->create();

        // No TenantSetting record — config default is true
        $slackUser = $this->resolver->findOrCreate($tenant, 'U1234567890');

        $this->assertTrue($slackUser->approved);
    }

    public function test_existing_user_approval_status_is_not_changed(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        // Pre-create an unapproved user
        SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'slack_user_id' => 'U1234567890',
            'approved' => false,
        ]);

        // findOrCreate should find the existing user, not update approval
        $slackUser = $this->resolver->findOrCreate($tenant, 'U1234567890');

        $this->assertFalse($slackUser->approved);
    }

    public function test_new_user_includes_profile_data(): void
    {
        $tenant = Tenant::factory()->create();
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::AUTO_APPROVE_USERS,
            'value' => '1',
        ]);

        $profile = [
            'real_name' => 'John Doe',
            'display_name' => 'johndoe',
            'avatar_url' => 'https://example.com/avatar.jpg',
        ];

        $slackUser = $this->resolver->findOrCreate($tenant, 'U1234567890', $profile);

        $this->assertTrue($slackUser->approved);
        $this->assertEquals('John Doe', $slackUser->real_name);
        $this->assertEquals('johndoe', $slackUser->display_name);
        $this->assertEquals('https://example.com/avatar.jpg', $slackUser->avatar_url);
    }
}
