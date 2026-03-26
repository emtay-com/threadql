<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Table;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class DeleteController extends Controller
{
    /**
     * Delete a table for a tenant.
     */
    public function __invoke(Tenant $tenant, Table $table): Response
    {
        if (Gate::denies('delete', [$table, $tenant])) {
            abort(404);
        }

        $table->delete();

        return response()->noContent();
    }
}
