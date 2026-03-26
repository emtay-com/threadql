<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class TablePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the table can be updated.
     */
    public function update(mixed $user, Table $table, Tenant $tenant): bool
    {
        return $table->tenant_id === $tenant->id;
    }

    /**
     * Determine if the table can be deleted.
     */
    public function delete(mixed $user, Table $table, Tenant $tenant): bool
    {
        return $table->tenant_id === $tenant->id;
    }

    /**
     * Determine if the table can be restored.
     */
    public function restore(mixed $user, Table $table, Tenant $tenant): bool
    {
        return $table->tenant_id === $tenant->id;
    }
}
