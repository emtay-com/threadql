<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Definition;

use App\Http\Controllers\Controller;
use App\Http\Payloads\DefinitionPayload;
use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Create a definition for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'definition' => 'required|string',
        ]);

        $definition = Definition::create([
            'tenant_id' => $tenant->id,
            'user_id' => 'admin',
            'thread_id' => null,
            'priority' => 0,
            'subject' => $validated['subject'],
            'definition' => $validated['definition'],
        ]);

        return response()->json(new DefinitionPayload($definition), 201);
    }
}
