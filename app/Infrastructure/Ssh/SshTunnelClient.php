<?php

declare(strict_types=1);

namespace App\Infrastructure\Ssh;

use App\Exceptions\SshTunnelException;
use App\Models\Datasource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the SSH tunnel manager sidecar.
 *
 * Requests a persistent SSH port-forward tunnel for a Datasource and returns
 * a TunnelConnection describing the local host/port to connect through.
 */
class SshTunnelClient
{
    private string $managerUrl;

    public function __construct(string $managerUrl)
    {
        $this->managerUrl = rtrim($managerUrl, '/');
    }

    /**
     * Get or create an SSH tunnel for the given datasource.
     *
     * @param  Datasource $datasource  The datasource that requires SSH tunnelling
     * @param  string     $remoteHost  The DB host reachable from the bastion
     * @param  int        $remotePort  The DB port reachable from the bastion
     * @return TunnelConnection        Host/port to use for the DB connection
     */
    public function getOrCreateTunnel(
        Datasource $datasource,
        string $remoteHost,
        int $remotePort,
    ): TunnelConnection {
        $payload = [
            'datasource_id' => (string) $datasource->id,
            'ssh_host' => $datasource->ssh_host,
            'ssh_port' => $datasource->ssh_port ?? 22,
            'ssh_username' => $datasource->ssh_username,
            'ssh_private_key' => $datasource->ssh_private_key,
            'ssh_password' => $datasource->ssh_password,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
        ];

        try {
            $response = Http::timeout(15)
                ->post("{$this->managerUrl}/tunnels", $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new SshTunnelException(
                "Cannot reach SSH tunnel manager at {$this->managerUrl}: {$e->getMessage()}",
                $e,
            );
        } catch (RequestException $e) {
            $status = $e->response->status();
            $detail = $e->response->json('detail') ?? $e->getMessage();
            Log::error('SSH tunnel manager error', [
                'datasource_id' => $datasource->id,
                'status' => $status,
                'detail' => $detail,
            ]);
            throw new SshTunnelException("SSH tunnel manager returned HTTP {$status}: {$detail}", $e);
        }

        $localPort = $response->json('local_port');
        if (! is_int($localPort) || $localPort < 1) {
            throw new SshTunnelException('SSH tunnel manager returned an invalid port');
        }

        $tunnelHost = parse_url($this->managerUrl, PHP_URL_HOST) ?? 'threadql-ssh-tunnel';

        return new TunnelConnection(host: $tunnelHost, port: $localPort);
    }
}
