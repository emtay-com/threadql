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
use Illuminate\Support\Facades\Gate;

class PutController extends Controller
{
    /**
     * Update a datasource for a tenant.
     */
    public function __invoke(Request $request, Tenant $tenant, Datasource $datasource): JsonResponse
    {
        if (Gate::denies('update', [$datasource, $tenant])) {
            abort(404);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            // DSN parts are optional for update
            'driver' => 'sometimes|string|in:mysql,pgsql',
            'host' => 'sometimes|nullable|string|max:255',
            'port' => 'sometimes|nullable|integer|min:1|max:65535',
            'database' => 'sometimes|nullable|string|max:255',
            'username' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string|max:1000',
            // Other fields
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

        // Update label (always required)
        $datasource->label = $validated['label'];

        // Check if DSN parts are provided - only rebuild if ALL required parts are present
        $hasDsnParts = $this->hasDsnParts($validated);
        if ($hasDsnParts) {
            $dsn = (new DsnBuilder())
                ->driver($validated['driver'] ?? 'mysql')
                ->host($validated['host'])
                ->port((int) $validated['port'])
                ->database($validated['database'])
                ->username($validated['username'])
                ->password($validated['password'] ?? '')
                ->build();

            $datasource->dsn = $dsn;
        }

        // Update other optional fields if provided
        if (array_key_exists('allowed_schemas', $validated)) {
            $datasource->allowed_schemas_json = $validated['allowed_schemas'];
        }

        if (array_key_exists('default_limit', $validated) && $validated['default_limit'] !== null) {
            $datasource->default_limit = $validated['default_limit'];
        }

        if (array_key_exists('query_timeout_seconds', $validated) && $validated['query_timeout_seconds'] !== null) {
            $datasource->query_timeout_seconds = $validated['query_timeout_seconds'];
        }

        if (array_key_exists('timezone', $validated) && $validated['timezone'] !== null) {
            $datasource->timezone = $validated['timezone'];
        }

        // Update SSH fields if provided
        if (array_key_exists('use_ssh', $validated)) {
            $datasource->use_ssh = $validated['use_ssh'];
        }

        if (array_key_exists('ssh_host', $validated)) {
            $datasource->ssh_host = $validated['ssh_host'];
        }

        if (array_key_exists('ssh_port', $validated)) {
            $datasource->ssh_port = $validated['ssh_port'];
        }

        if (array_key_exists('ssh_username', $validated)) {
            $datasource->ssh_username = $validated['ssh_username'];
        }

        if (array_key_exists('ssh_password', $validated)) {
            $datasource->ssh_password = $validated['ssh_password'];
        }

        if (array_key_exists('ssh_private_key', $validated)) {
            $datasource->ssh_private_key = $validated['ssh_private_key'];
        }

        if (array_key_exists('ssh_public_key', $validated)) {
            $datasource->ssh_public_key = $validated['ssh_public_key'];
        }

        $datasource->save();

        return response()->json(new DataSourcePayload($datasource));
    }

    /**
     * Check if all required DSN parts are provided.
     *
     * @param array<string, mixed> $validated
     */
    private function hasDsnParts(array $validated): bool
    {
        $requiredParts = ['host', 'port', 'database', 'username'];

        foreach ($requiredParts as $part) {
            if (! array_key_exists($part, $validated) || $validated[$part] === null || $validated[$part] === '') {
                return false;
            }
        }

        return true;
    }
}
