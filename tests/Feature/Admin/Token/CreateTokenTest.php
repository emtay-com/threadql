<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Token;

use App\Enums\UserLevel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CreateTokenTest extends TestCase
{
    private int $ipOctet;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
            'auth.master_admin_password' => 'master-secret-password',
        ]);

        $this->ipOctet = random_int(1, 254);
    }

    public function test_it_requires_username_and_password(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.1",
        ])->postJson('/api/admin/token', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['username', 'password']);
    }

    public function test_it_allows_master_login_with_master_username(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.2",
        ])->postJson('/api/admin/token', [
            'username' => 'master',
            'password' => 'master-secret-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'token_type',
            'expires_in',
            'user' => ['is_master', 'level', 'identifier', 'username', 'tenant_id'],
        ]);
        $response->assertJsonMissing(['refresh_token']);
        $response->assertJson([
            'token_type' => 'bearer',
            'user' => [
                'is_master' => true,
                'level' => UserLevel::MASTER->value,
                'username' => 'master',
                'tenant_id' => null,
            ],
        ]);
        $response->assertCookie('refresh_token');
    }

    public function test_it_allows_tenant_user_login(): void
    {
        $tenant = Tenant::factory()->create();
        $plainPassword = 'tenant-secret-password';

        $user = User::factory()->forTenant($tenant)->create([
            'username' => 'tenant_admin',
            'email' => 'tenant.admin@example.com',
            'password' => Hash::make($plainPassword, [
                'rounds' => 12,
            ]),
            'level' => UserLevel::TENANT->value,
        ]);

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.3",
        ])->postJson('/api/admin/token', [
            'username' => $user->username,
            'password' => $plainPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['refresh_token']);
        $response->assertJson([
            'token_type' => 'bearer',
            'user' => [
                'is_master' => false,
                'level' => UserLevel::TENANT->value,
                'username' => $user->username,
                'tenant_id' => $tenant->id,
            ],
        ]);
        $response->assertCookie('refresh_token');
    }

    public function test_it_rejects_invalid_credentials(): void
    {
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.4",
        ])->postJson('/api/admin/token', [
            'username' => 'master',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'error' => 'Invalid credentials',
        ]);
    }

    public function test_it_can_refresh_token_via_cookie(): void
    {
        $loginResponse = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.6",
        ])->postJson('/api/admin/token', [
            'username' => 'master',
            'password' => 'master-secret-password',
        ]);

        $loginResponse->assertStatus(200);

        $originalToken = $loginResponse->json('token');

        // withCredentials is required for JSON requests to include cookies
        $refreshResponse = $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', $originalToken)
            ->postJson('/api/admin/token/refresh');

        $refreshResponse->assertStatus(200);
        $refreshResponse->assertJsonStructure(['token', 'token_type', 'expires_in']);
        $refreshResponse->assertJsonMissing(['refresh_token']);
        $this->assertNotEmpty($refreshResponse->json('token'));
        $refreshResponse->assertCookie('refresh_token');
    }

    public function test_refresh_rejects_missing_cookie(): void
    {
        $refreshResponse = $this->postJson('/api/admin/token/refresh');

        $refreshResponse->assertStatus(401);
        $refreshResponse->assertJson([
            'error' => 'Could not refresh token',
        ]);
    }

    public function test_refresh_rejects_invalid_cookie_token(): void
    {
        $refreshResponse = $this->withCredentials()
            ->withUnencryptedCookie('refresh_token', 'invalid-token-here')
            ->postJson('/api/admin/token/refresh');

        $refreshResponse->assertStatus(401);
        $refreshResponse->assertJson([
            'error' => 'Could not refresh token',
        ]);
    }

    public function test_logout_clears_refresh_cookie(): void
    {
        $response = $this->postJson('/api/admin/token/logout');

        $response->assertStatus(200);
        $response->assertJson([
            'message' => 'Logged out',
        ]);
        // The response should contain a cookie that expires the refresh_token
        $this->assertRefreshCookieExpired($response);
    }

    public function test_it_applies_rate_limit_after_three_attempts_per_minute(): void
    {
        $request = $this->withServerVariables([
            'REMOTE_ADDR' => "10.10.{$this->ipOctet}.5",
        ]);

        $payload = [
            'username' => 'master',
            'password' => 'wrong-password',
        ];

        $request->postJson('/api/admin/token', $payload)
            ->assertStatus(401);
        $request->postJson('/api/admin/token', $payload)
            ->assertStatus(401);
        $request->postJson('/api/admin/token', $payload)
            ->assertStatus(401);

        $request->postJson('/api/admin/token', $payload)
            ->assertStatus(429);
    }

    /**
     * Extract the encrypted cookie value from a response so it can be forwarded to subsequent requests.
     */
    private function getEncryptedCookieValue(TestResponse $response, string $name): string
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === $name);

        $this->assertNotNull($cookie, "Cookie [{$name}] not found in response.");

        return $cookie->getValue();
    }

    /**
     * Assert that the refresh_token cookie in the response is expired (forgotten).
     */
    private function assertRefreshCookieExpired(TestResponse $response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'refresh_token');

        $this->assertNotNull($cookie, 'refresh_token cookie not found in response.');
        $this->assertTrue($cookie->getExpiresTime() < time(), 'Expected refresh_token cookie to be expired.');
    }
}
