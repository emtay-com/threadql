<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\DataSource;

use App\Http\Controllers\Controller;
use App\Infrastructure\Connectors\DynamicDatabaseConnector;
use App\Infrastructure\Dsn\DsnBuilder;
use App\Models\Datasource;
use App\Models\Tenant;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TestConnectionController extends Controller
{
    public function __construct(
        private readonly DynamicDatabaseConnector $connector,
    ) {
    }

    /**
     * Test a database connection using provided DSN fields without persisting.
     */
    public function __invoke(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'driver' => 'sometimes|string|in:mysql,pgsql',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'database' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:1000',
            'query_timeout_seconds' => 'nullable|integer|min:1|max:3600',
            'use_ssh' => 'boolean',
            'ssh_host' => 'required_if:use_ssh,true|nullable|string|max:255',
            'ssh_port' => 'nullable|integer|min:1|max:65535',
            'ssh_username' => 'required_if:use_ssh,true|nullable|string|max:255',
            'ssh_password' => 'nullable|string|max:4096',
            'ssh_private_key' => 'nullable|string|max:16384',
            'ssh_public_key' => 'nullable|string|max:4096',
        ]);

        $dsn = (new DsnBuilder())
            ->driver($validated['driver'] ?? 'mysql')
            ->host($validated['host'])
            ->port($validated['port'])
            ->database($validated['database'])
            ->username($validated['username'])
            ->password($validated['password'])
            ->build();

        $datasource = new Datasource([
            'tenant_id' => $tenant->id,
            'dsn' => $dsn,
            'query_timeout_seconds' => $validated['query_timeout_seconds'] ?? null,
            'use_ssh' => $validated['use_ssh'] ?? false,
            'ssh_host' => $validated['ssh_host'] ?? null,
            'ssh_port' => $validated['ssh_port'] ?? null,
            'ssh_username' => $validated['ssh_username'] ?? null,
            'ssh_password' => $validated['ssh_password'] ?? null,
            'ssh_private_key' => $validated['ssh_private_key'] ?? null,
            'ssh_public_key' => $validated['ssh_public_key'] ?? null,
        ]);

        try {
            $timeoutSeconds = $datasource->query_timeout_seconds;
            $timeoutStrategy = $timeoutSeconds ? $this->connector->getTimeoutStrategy($datasource) : null;

            $this->connector->withConnection($datasource, function ($connection) use (
                $timeoutStrategy,
                $timeoutSeconds
            ) {
                if ($timeoutStrategy && $timeoutSeconds) {
                    $timeoutStrategy->setTimeout($connection, $timeoutSeconds);
                }
                $connection->selectOne('SELECT 1');
            });

            return new JsonResponse(
                [
                    'data' => [
                        'connected' => true,
                    ],
                ],
                Response::HTTP_OK,
            );
        } catch (Exception $e) {
            return new JsonResponse(
                [
                    'data' => [
                        'connected' => false,
                        'error' => $e->getMessage(),
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }
}
