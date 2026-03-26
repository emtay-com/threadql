<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Auth\Access\HandlesAuthorization;

class DatasourcePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the datasource can be updated.
     */
    public function update(mixed $user, Datasource $datasource, Tenant $tenant): bool
    {
        return $datasource->tenant_id === $tenant->id;
    }

    /**
     * Determine if the datasource can be operated on (ping, scan, etc.).
     */
    public function operate(mixed $user, Datasource $datasource, Tenant $tenant): bool
    {
        return $datasource->tenant_id === $tenant->id;
    }
}
