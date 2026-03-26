<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataSource;

use App\Http\Controllers\Controller;
use App\Http\Payloads\DataSourcePayload;
use App\Infrastructure\Dsn\DsnBuilder;
use App\Models\Datasource;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    /**
     * Create a new datasource for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'driver' => 'sometimes|string|in:mysql,pgsql',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'database' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:1000',
            'allowed_schemas' => 'nullable|array',
            'allowed_schemas.*' => 'string|max:255',
            'default_limit' => 'nullable|integer|min:1|max:10000',
            'query_timeout_seconds' => 'nullable|integer|min:1|max:3600',
            'timezone' => 'nullable|string|timezone',
            // SSH tunnel fields
            'use_ssh' => 'boolean',
            'ssh_host' => 'required_if:use_ssh,true|nullable|string|max:255',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_username' => 'required_if:use_ssh,true|nullable|string|max:255',
            'ssh_password' => 'nullable|string|max:4096',
            'ssh_private_key' => 'nullable|string|max:16384',
            'ssh_public_key' => 'nullable|string|max:4096',
        ]);

        // Build DSN from components
        $dsn = (new DsnBuilder())
            ->driver($validated['driver'] ?? 'mysql')
            ->host($validated['host'])
            ->port($validated['port'])
            ->database($validated['database'])
            ->username($validated['username'])
            ->password($validated['password'])
            ->build();

        $datasource = Datasource::create([
            'tenant_id' => $tenant->id,
            'label' => $validated['label'],
            'dsn' => $dsn,
            'allowed_schemas_json' => $validated['allowed_schemas'] ?? null,
            'default_limit' => $validated['default_limit'] ?? 200,
            'query_timeout_seconds' => $validated['query_timeout_seconds'] ?? 60,
            'timezone' => $validated['timezone'] ?? 'UTC',
            'use_ssh' => $validated['use_ssh'] ?? false,
            'ssh_host' => $validated['ssh_host'] ?? null,
            'ssh_port' => $validated['ssh_port'] ?? null,
            'ssh_username' => $validated['ssh_username'] ?? null,
            'ssh_password' => $validated['ssh_password'] ?? null,
            'ssh_private_key' => $validated['ssh_private_key'] ?? null,
            'ssh_public_key' => $validated['ssh_public_key'] ?? null,
        ]);

        return response()->json(new DataSourcePayload($datasource), 201);
    }
}
