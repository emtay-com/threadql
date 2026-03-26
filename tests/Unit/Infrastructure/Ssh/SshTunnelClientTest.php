<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Ssh;

use App\Exceptions\SshTunnelException;
use App\Infrastructure\Ssh\SshTunnelClient;
use App\Infrastructure\Ssh\TunnelConnection;
use App\Models\Datasource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SshTunnelClientTest extends TestCase
{
    private const MANAGER_URL = 'http://threadql-ssh-tunnel:8092';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeDatasource(array $attributes = []): Datasource
    {
        $mock = Mockery::mock(Datasource::class);
        $mock->allows('__get')
            ->andReturnUsing(function (string $key) use ($attributes) {
                        return $attributes[$key] ?? null;
                    });
        $mock->allows('getAttribute')
            ->andReturnUsing(function (string $key) use ($attributes) {
                        return $attributes[$key] ?? null;
                    });
        // offsetExists is called by Eloquent's __isset(), which PHP triggers for the ?? operator
        $mock->allows('offsetExists')
            ->andReturnUsing(function (string $key) use ($attributes) {
                        return isset($attributes[$key]);
                    });

        return $mock;
    }

    #[Test]
    public function it_returns_tunnel_connection_on_success(): void
    {
        Http::fake([
            self::MANAGER_URL.'/tunnels' => Http::response([
                'datasource_id' => '42',
                'local_port' => 13300,
                'status' => 'created',
            ], 200),
        ]);

        $datasource = $this->makeDatasource([
            'id' => 42,
            'ssh_host' => 'bastion.example.com',
            'ssh_port' => 22,
            'ssh_username' => 'ec2-user',
            'ssh_private_key' => '-----BEGIN RSA PRIVATE KEY-----',
            'ssh_password' => null,
        ]);

        $client = new SshTunnelClient(self::MANAGER_URL);
        $tunnel = $client->getOrCreateTunnel($datasource, 'db.internal', 3306);

        $this->assertInstanceOf(TunnelConnection::class, $tunnel);
        $this->assertEquals('threadql-ssh-tunnel', $tunnel->host);
        $this->assertEquals(13300, $tunnel->port);
    }

    #[Test]
    public function it_posts_correct_payload_to_manager(): void
    {
        Http::fake([
            self::MANAGER_URL.'/tunnels' => Http::response([
                'datasource_id' => '7',
                'local_port' => 13305,
                'status' => 'reused',
            ], 200),
        ]);

        $datasource = $this->makeDatasource([
            'id' => 7,
            'ssh_host' => 'jump.corp.com',
            'ssh_port' => 2222,
            'ssh_username' => 'admin',
            'ssh_private_key' => 'private-key-content',
            'ssh_password' => null,
        ]);

        $client = new SshTunnelClient(self::MANAGER_URL);
        $client->getOrCreateTunnel($datasource, 'postgres.internal', 5432);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === self::MANAGER_URL.'/tunnels'
                && $body['datasource_id'] === '7'
                && $body['ssh_host'] === 'jump.corp.com'
                && $body['ssh_port'] === 2222
                && $body['ssh_username'] === 'admin'
                && $body['ssh_private_key'] === 'private-key-content'
                && $body['remote_host'] === 'postgres.internal'
                && $body['remote_port'] === 5432;
        });
    }

    #[Test]
    public function it_throws_ssh_tunnel_exception_on_http_error(): void
    {
        Http::fake([
            self::MANAGER_URL.'/tunnels' => Http::response([
                'detail' => 'SSH connection failed: Connection refused',
            ], 502),
        ]);

        $datasource = $this->makeDatasource([
            'id' => 99,
            'ssh_host' => 'bad-bastion.com',
            'ssh_port' => 22,
            'ssh_username' => 'user',
            'ssh_private_key' => 'key',
            'ssh_password' => null,
        ]);

        $client = new SshTunnelClient(self::MANAGER_URL);

        $this->expectException(SshTunnelException::class);
        $this->expectExceptionMessage('HTTP 502');

        $client->getOrCreateTunnel($datasource, 'db.internal', 3306);
    }

    #[Test]
    public function it_throws_ssh_tunnel_exception_on_connection_failure(): void
    {
        Http::fake([
            self::MANAGER_URL.'/tunnels' => Http::failedConnection(),
        ]);

        $datasource = $this->makeDatasource([
            'id' => 1,
            'ssh_host' => 'bastion.example.com',
            'ssh_port' => 22,
            'ssh_username' => 'user',
            'ssh_private_key' => 'key',
            'ssh_password' => null,
        ]);

        $client = new SshTunnelClient(self::MANAGER_URL);

        $this->expectException(SshTunnelException::class);

        $client->getOrCreateTunnel($datasource, 'db.internal', 3306);
    }

    #[Test]
    public function it_throws_when_manager_returns_invalid_port(): void
    {
        Http::fake([
            self::MANAGER_URL.'/tunnels' => Http::response([
                'datasource_id' => '1',
                'local_port' => 0,
                'status' => 'created',
            ], 200),
        ]);

        $datasource = $this->makeDatasource([
            'id' => 1,
            'ssh_host' => 'bastion.example.com',
            'ssh_port' => 22,
            'ssh_username' => 'user',
            'ssh_private_key' => 'key',
            'ssh_password' => null,
        ]);

        $client = new SshTunnelClient(self::MANAGER_URL);

        $this->expectException(SshTunnelException::class);
        $this->expectExceptionMessage('invalid port');

        $client->getOrCreateTunnel($datasource, 'db.internal', 3306);
    }
}
