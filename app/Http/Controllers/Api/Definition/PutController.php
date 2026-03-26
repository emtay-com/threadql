<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Definition;

use App\Http\Controllers\Controller;
use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PutController extends Controller
{
    /**
     * Update a definition for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant, Definition $definition): Response
    {
        if (Gate::denies('update', [$definition, $tenant])) {
            abort(404);
        }

        $validated = $request->validate([
            'definition' => 'required|string|max:1000',
        ]);

        $definition->definition = $validated['definition'];
        $definition->save();

        return response()->noContent();
    }
}
