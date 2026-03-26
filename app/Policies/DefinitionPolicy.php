<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class DefinitionPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the definition can be updated.
     */
    public function update(mixed $user, Definition $definition, Tenant $tenant): bool
    {
        return $definition->tenant_id === $tenant->id;
    }

    /**
     * Determine if the definition can be deleted.
     */
    public function delete(mixed $user, Definition $definition, Tenant $tenant): bool
    {
        return $definition->tenant_id === $tenant->id;
    }
}
