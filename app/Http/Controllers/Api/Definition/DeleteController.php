<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Definition;

use App\Http\Controllers\Controller;
use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeleteController extends Controller
{
    /**
     * Delete a definition for a tenant.
     */
    public function __invoke(Tenant $tenant, Definition $definition): Response
    {
        if (Gate::denies('delete', [$definition, $tenant])) {
            abort(404);
        }

        $definition->delete();

        return response()->noContent();
    }
}
