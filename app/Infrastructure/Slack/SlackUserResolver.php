<?php

declare(strict_types=1);

namespace App\Infrastructure\Slack;

use App\Enums\TenantSettingEnum;
use App\Models\SlackUser;
use App\Models\Tenant;

/**
 * Service for resolving and creating SlackUser instances.
 *
 * Handles finding or creating SlackUser records based on Slack event data.
 * When creating a new user, consults the tenant's AUTO_APPROVE_USERS setting
 * to determine whether the user should be automatically approved.
 */
class SlackUserResolver
{
    /**
     * Find or create a SlackUser for the given tenant and Slack user ID.
     *
     * When creating a new user, the tenant's AUTO_APPROVE_USERS setting is consulted
     * to determine the initial approval status.
     *
     * @param  Tenant  $tenant  The tenant
     * @param  string  $slackUserId  The Slack user ID (e.g., 'U02PUUJSX96')
     * @param  array  $profile  Optional profile data from Slack API
     * @return SlackUser The SlackUser instance
     */
    public function findOrCreate(Tenant $tenant, string $slackUserId, array $profile = []): SlackUser
    {
        $autoApprove = $tenant->getSetting(TenantSettingEnum::AUTO_APPROVE_USERS)->isEnabled();

        return SlackUser::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slack_user_id' => $slackUserId,
            ],
            [
                'real_name' => $profile['real_name'] ?? null,
                'display_name' => $profile['display_name'] ?? null,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'approved' => $autoApprove,
            ]
        );
    }

    /**
     * Extract user profile data from Slack event payload.
     *
     * @param  array  $payload  The Slack event payload
     * @return array Profile data array
     */
    public function extractProfileFromPayload(array $payload): array
    {
        $user = $payload['event']['user'] ?? null;

        if (! $user) {
            return [];
        }

        return [
            'real_name' => $user['real_name'] ?? null,
            'display_name' => $user['name'] ?? null,
            'avatar_url' => $user['profile']['image_192'] ?? null,
        ];
    }
}
