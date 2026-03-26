<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\SlackUser;
use App\Models\Tenant;
use Tests\TestCase;

class SlackUserApprovedTest extends TestCase
{
    public function test_approved_defaults_to_false(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $slackUser->refresh();

        $this->assertFalse($slackUser->approved);
    }

    public function test_approved_is_cast_to_boolean(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'approved' => true,
        ]);

        $this->assertTrue($slackUser->approved);
        $this->assertIsBool($slackUser->approved);
    }

    public function test_approved_can_be_set_to_true(): void
    {
        $tenant = Tenant::factory()->create();
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
            'approved' => false,
        ]);

        $slackUser->update([
            'approved' => true,
        ]);
        $slackUser->refresh();

        $this->assertTrue($slackUser->approved);
    }
}
