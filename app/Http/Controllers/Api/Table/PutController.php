<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Table;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PutController extends Controller
{
    /**
     * Update a table's priority for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant, Table $table): Response
    {
        if (Gate::denies('update', [$table, $tenant])) {
            abort(404);
        }

        $validated = $request->validate([
            'priority' => 'required|integer|min:0|max:100',
        ]);

        $table->priority = $validated['priority'];
        $table->save();

        return response()->noContent();
    }
}
