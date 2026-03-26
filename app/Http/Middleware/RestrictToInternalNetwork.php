<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts access to internal network traffic only.
 *
 * This middleware ensures that requests can only come from:
 * - Private network ranges (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
 * - Localhost (127.0.0.1, ::1)
 * - Kubernetes pod networks (configurable)
 * - Additional IPs/CIDRs specified via MCP_ALLOWED_IPS env var
 */
class RestrictToInternalNetwork
{
    /**
     * Private network CIDR ranges that are always allowed.
     */
    private const PRIVATE_RANGES = [
        '127.0.0.0/8',      // Localhost IPv4
        '10.0.0.0/8',       // Class A private
        '172.16.0.0/12',    // Class B private
        '192.168.0.0/16',   // Class C private
        '::1/128',          // Localhost IPv6
        'fc00::/7',         // IPv6 unique local
        'fe80::/10',        // IPv6 link-local
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip validation in testing environment
        if (app()->environment('testing')) {
            return $next($request);
        }

        $clientIp = $request->ip();

        if ($clientIp === null) {
            Log::warning('MCP request blocked: unable to determine client IP');

            return response()->json([
                'error' => 'Forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $this->isAllowedIp($clientIp)) {
            Log::warning('MCP request blocked: IP not in allowed range', [
                'client_ip' => $clientIp,
            ]);

            return response()->json([
                'error' => 'Forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Check if the given IP is allowed.
     */
    private function isAllowedIp(string $ip): bool
    {
        // Check private network ranges
        foreach (self::PRIVATE_RANGES as $cidr) {
            if ($this->ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        // Check additional allowed IPs from config
        $additionalIps = $this->getAdditionalAllowedIps();
        foreach ($additionalIps as $allowed) {
            $allowed = trim($allowed);
            if (empty($allowed)) {
                continue;
            }

            // Check if it's a CIDR range or exact IP
            if (str_contains($allowed, '/')) {
                if ($this->ipInCidr($ip, $allowed)) {
                    return true;
                }
            } elseif ($ip === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get additional allowed IPs from environment.
     *
     * @return array<string>
     */
    private function getAdditionalAllowedIps(): array
    {
        $envValue = config('mcp.allowed_ips', '');

        if (empty($envValue)) {
            return [];
        }

        return explode(',', $envValue);
    }

    /**
     * Check if an IP address is within a CIDR range.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        // Handle IPv6
        if (str_contains($ip, ':') !== str_contains($cidr, ':')) {
            // IP version mismatch
            return false;
        }

        if (str_contains($ip, ':')) {
            return $this->ipv6InCidr($ip, $cidr);
        }

        return $this->ipv4InCidr($ip, $cidr);
    }

    /**
     * Check if an IPv4 address is within a CIDR range.
     */
    private function ipv4InCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Check if an IPv6 address is within a CIDR range.
     */
    private function ipv6InCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Create the mask
        $mask = str_repeat('f', (int) ($bits / 4));
        $remainder = $bits % 4;
        if ($remainder > 0) {
            $mask .= dechex(0xF << (4 - $remainder));
        }
        $mask = str_pad($mask, 32, '0');
        $maskBin = pack('H*', $mask);

        return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
    }
}
