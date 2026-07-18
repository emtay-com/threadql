<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\RestrictToInternalNetwork;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RestrictToInternalNetworkTest extends TestCase
{
    private RestrictToInternalNetwork $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new RestrictToInternalNetwork();

        // Ensure we're not in testing environment for these tests
        // so the middleware actually runs
        $this->app['env'] = 'local';
    }

    #[DataProvider('allowedPrivateIpsProvider')]
    public function test_it_allows_private_network_ips(string $ip): void
    {
        $request = $this->createRequestWithIp($ip);
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertTrue($called, "Expected IP {$ip} to be allowed");
        $this->assertEquals(200, $response->getStatusCode());
    }

    #[DataProvider('blockedPublicIpsProvider')]
    public function test_it_blocks_public_ips(string $ip): void
    {
        $request = $this->createRequestWithIp($ip);
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertFalse($called, "Expected IP {$ip} to be blocked");
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_it_allows_additional_ips_from_config(): void
    {
        config([
            'mcp.allowed_ips' => '203.0.113.50',
        ]);

        $request = $this->createRequestWithIp('203.0.113.50');
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_it_allows_cidr_ranges_from_config(): void
    {
        config([
            'mcp.allowed_ips' => '203.0.113.0/24',
        ]);

        $request = $this->createRequestWithIp('203.0.113.100');
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertTrue($called);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_it_allows_multiple_configured_ips(): void
    {
        config([
            'mcp.allowed_ips' => '203.0.113.50,198.51.100.0/24',
        ]);

        // Test first IP
        $request1 = $this->createRequestWithIp('203.0.113.50');
        $called1 = false;
        $this->middleware->handle($request1, function () use (&$called1) {
            $called1 = true;

            return new Response('OK');
        });
        $this->assertTrue($called1);

        // Test IP in CIDR range
        $request2 = $this->createRequestWithIp('198.51.100.25');
        $called2 = false;
        $this->middleware->handle($request2, function () use (&$called2) {
            $called2 = true;

            return new Response('OK');
        });
        $this->assertTrue($called2);
    }

    public function test_it_blocks_ip_not_in_configured_cidr(): void
    {
        config([
            'mcp.allowed_ips' => '203.0.113.0/24',
        ]);

        $request = $this->createRequestWithIp('203.0.114.1'); // Different subnet
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertFalse($called);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_it_returns_json_error_response(): void
    {
        $request = $this->createRequestWithIp('8.8.8.8');

        $response = $this->middleware->handle($request, function () {
            return new Response('OK');
        });

        $this->assertEquals(403, $response->getStatusCode());
        $content = json_decode($response->getContent(), true);
        $this->assertEquals([
            'error' => 'Forbidden',
        ], $content);
    }

    public function test_it_skips_validation_in_testing_environment(): void
    {
        // Reset to testing environment
        $this->app['env'] = 'testing';

        $request = $this->createRequestWithIp('8.8.8.8'); // Public IP
        $called = false;

        $response = $this->middleware->handle($request, function () use (&$called) {
            $called = true;

            return new Response('OK');
        });

        $this->assertTrue($called, 'Should skip validation in testing env');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedPrivateIpsProvider(): array
    {
        return [
            'localhost IPv4' => ['127.0.0.1'],
            'localhost range' => ['127.0.0.5'],
            'class A private' => ['10.0.0.1'],
            'class A private 2' => ['10.255.255.255'],
            'class B private start' => ['172.16.0.1'],
            'class B private end' => ['172.31.255.255'],
            'class C private' => ['192.168.1.1'],
            'class C private 2' => ['192.168.100.50'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedPublicIpsProvider(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare dns' => ['1.1.1.1'],
            'random public' => ['203.0.113.50'],
            'class B outside private' => ['172.32.0.1'],
            'class B before private' => ['172.15.255.255'],
        ];
    }

    private function createRequestWithIp(string $ip): Request
    {
        $request = Request::create('/mcp', 'POST');
        $request->server->set('REMOTE_ADDR', $ip);

        return $request;
    }
}
