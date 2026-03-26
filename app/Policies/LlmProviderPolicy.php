<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LlmProvider;
use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class LlmProviderPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the LLM provider can be operated on (ping, test, etc.).
     */
    public function operate(mixed $user, LlmProvider $llmProvider, Tenant $tenant): bool
    {
        return $llmProvider->tenant_id === $tenant->id;
    }

    /**
     * Determine if the LLM provider can be updated.
     */
    public function update(mixed $user, LlmProvider $llmProvider, Tenant $tenant): bool
    {
        return $llmProvider->tenant_id === $tenant->id;
    }

    /**
     * Determine if the LLM provider can be deleted.
     */
    public function delete(mixed $user, LlmProvider $llmProvider, Tenant $tenant): bool
    {
        return $llmProvider->tenant_id === $tenant->id;
    }
}
