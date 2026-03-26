<?php

declare(strict_types=1);

namespace Feature\Admin\Tenant;

use App\Infrastructure\Slack\SlackClientFactory;
use App\Models\MasterAdmin;
use App\Models\Tenant;
use JoliCode\Slack\Api\Client;
use JoliCode\Slack\Api\Model\AuthTestGetResponse200;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class TestSlackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'jwt.secret' => 'test-jwt-secret-key-for-testing-only',
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->getJson("/api/admin/tenants/{$tenant->id}/test-slack");

        $response->assertStatus(401);
    }

    public function test_it_returns_404_for_nonexistent_tenant(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/tenants/99999/test-slack');

        $response->assertStatus(404);
    }

    public function test_it_returns_422_when_no_bot_token_configured(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => null,
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/test-slack");

        $response->assertStatus(422);
        $response->assertJsonPath('data.success', false);
        $response->assertJsonPath('data.message', 'No bot token configured for this tenant');
    }

    public function test_it_returns_success_with_auth_details(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => 'xoxb-test-token',
        ]);

        $authResponse = new AuthTestGetResponse200();
        $authResponse->setOk(true);
        $authResponse->setTeam('Acme Corp');
        $authResponse->setTeamId('T12345');
        $authResponse->setUser('threadql-bot');
        $authResponse->setUserId('U12345');
        $authResponse->setBotId('B12345');

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('authTest')
            ->willReturn($authResponse);

        $mockFactory = $this->createMock(SlackClientFactory::class);
        $mockFactory->expects($this->once())
            ->method('create')
            ->with('xoxb-test-token')
            ->willReturn($mockClient);

        $this->app->instance(SlackClientFactory::class, $mockFactory);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/test-slack");

        $response->assertStatus(200);
        $response->assertJsonPath('data.success', true);
        $response->assertJsonPath('data.message', 'Slack connection verified');
        $response->assertJsonPath('data.team', 'Acme Corp');
        $response->assertJsonPath('data.team_id', 'T12345');
        $response->assertJsonPath('data.user', 'threadql-bot');
        $response->assertJsonPath('data.user_id', 'U12345');
        $response->assertJsonPath('data.bot_id', 'B12345');
    }

    public function test_it_returns_422_when_auth_test_fails(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => 'xoxb-bad-token',
        ]);

        $authResponse = new AuthTestGetResponse200();
        $authResponse->setOk(false);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('authTest')
            ->willReturn($authResponse);

        $mockFactory = $this->createMock(SlackClientFactory::class);
        $mockFactory->expects($this->once())
            ->method('create')
            ->with('xoxb-bad-token')
            ->willReturn($mockClient);

        $this->app->instance(SlackClientFactory::class, $mockFactory);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/test-slack");

        $response->assertStatus(422);
        $response->assertJsonPath('data.success', false);
        $this->assertStringContainsString('Unknown error', $response->json('data.message'));
    }

    public function test_it_returns_422_when_auth_test_throws_exception(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_bot_token' => 'xoxb-error-token',
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('authTest')
            ->willThrowException(new \Exception('Connection refused'));

        $mockFactory = $this->createMock(SlackClientFactory::class);
        $mockFactory->expects($this->once())
            ->method('create')
            ->with('xoxb-error-token')
            ->willReturn($mockClient);

        $this->app->instance(SlackClientFactory::class, $mockFactory);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/admin/tenants/{$tenant->id}/test-slack");

        $response->assertStatus(422);
        $response->assertJsonPath('data.success', false);
        $this->assertStringContainsString('Connection refused', $response->json('data.message'));
    }
}
