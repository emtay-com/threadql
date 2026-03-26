<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SlackUser;
use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class SlackUserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the slack user can be updated.
     */
    public function update(mixed $user, SlackUser $slackUser, Tenant $tenant): bool
    {
        return $slackUser->tenant_id === $tenant->id;
    }

    /**
     * Determine if the slack user can be deleted.
     */
    public function delete(mixed $user, SlackUser $slackUser, Tenant $tenant): bool
    {
        return $slackUser->tenant_id === $tenant->id;
    }

    /**
     * Determine if the slack user can be restored.
     */
    public function restore(mixed $user, SlackUser $slackUser, Tenant $tenant): bool
    {
        return $slackUser->tenant_id === $tenant->id;
    }
}
