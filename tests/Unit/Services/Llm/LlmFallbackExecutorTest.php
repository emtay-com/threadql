<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Enums\TenantSettingEnum;
use App\Exceptions\AllProvidersExhaustedException;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Jobs\SendEphemeralSqlDebug;
use App\Models\LlmProvider;
use App\Models\Query;
use App\Models\SlackUser;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\Thread;
use App\Services\Llm\FallbackExceptionClassifier;
use App\Services\Llm\LlmFallbackExecutor;
use App\Services\Llm\LlmProviderResolver;
use App\Services\Llm\PrismProviderMapper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Text\Response;
use Tests\TestCase;

class LlmFallbackExecutorTest extends TestCase
{
    private PrismProviderMapper $prismMapper;

    private LlmProviderResolver $providerResolver;

    private FallbackExceptionClassifier $classifier;

    private SlackUserSettingService $settingService;

    private LlmFallbackExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prismMapper = $this->createStub(PrismProviderMapper::class);
        $this->providerResolver = $this->createStub(LlmProviderResolver::class);
        $this->classifier = new FallbackExceptionClassifier();
        $this->settingService = $this->createStub(SlackUserSettingService::class);

        $this->executor = new LlmFallbackExecutor(
            $this->prismMapper,
            $this->providerResolver,
            $this->classifier,
            $this->settingService,
        );
    }

    public function test_success_on_first_provider(): void
    {
        $tenant = Tenant::factory()->create();
        $provider = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
        ]);
        $this->createFallbackSetting($tenant, 3);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider]);
        $this->prismMapper->method('generateText')
            ->willReturn($mockResponse);

        $result = $this->executor->executeWithFallback($tenant, [], $provider);

        $this->assertSame($mockResponse, $result['response']);
        $this->assertSame($provider->id, $result['provider']->id);
    }

    public function test_fallback_to_second_provider_after_fallbackable_error(): void
    {
        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'Primary',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'Fallback',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);

        $this->prismMapper->method('generateText')
            ->willReturnCallback(function (LlmProvider $p) use ($provider1, $mockResponse) {
                if ($p->id === $provider1->id) {
                    throw PrismRateLimitedException::make();
                }

                return $mockResponse;
            });

        $result = $this->executor->executeWithFallback($tenant, [], $provider1);

        $this->assertSame($mockResponse, $result['response']);
        $this->assertSame($provider2->id, $result['provider']->id);
    }

    public function test_non_fallbackable_exception_rethrown_immediately(): void
    {
        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
        ]);
        $this->createFallbackSetting($tenant, 3);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);

        $originalException = PrismException::providerRequestErrorWithDetails(
            'OpenAI',
            422,
            'invalid_request',
            'Bad request'
        );

        $this->prismMapper->method('generateText')
            ->willThrowException($originalException);

        $this->expectException(PrismException::class);
        $this->expectExceptionMessage('Bad request');

        $this->executor->executeWithFallback($tenant, [], $provider1);
    }

    public function test_all_providers_exhausted_exception_when_all_fail(): void
    {
        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'Provider A',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'Provider B',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);

        $this->prismMapper->method('generateText')
            ->willThrowException(PrismRateLimitedException::make());

        try {
            $this->executor->executeWithFallback($tenant, [], $provider1);
            $this->fail('Expected AllProvidersExhaustedException');
        } catch (AllProvidersExhaustedException $e) {
            $this->assertCount(2, $e->getProvidersTried());
            $this->assertStringContainsString('Provider A', $e->getMessage());
            $this->assertStringContainsString('Provider B', $e->getMessage());
        }
    }

    public function test_max_attempts_limits_providers_tried(): void
    {
        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'P1',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'P2',
        ]);
        $provider3 = LlmProvider::factory()->forTenant($tenant)->ollama()->create([
            'sort' => 2,
            'name' => 'P3',
        ]);
        $this->createFallbackSetting($tenant, 2);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2, $provider3]);

        $this->prismMapper->method('generateText')
            ->willThrowException(PrismRateLimitedException::make());

        try {
            $this->executor->executeWithFallback($tenant, [], $provider1);
            $this->fail('Expected AllProvidersExhaustedException');
        } catch (AllProvidersExhaustedException $e) {
            // Only 2 providers tried (limited by maxAttempts=2)
            $this->assertCount(2, $e->getProvidersTried());
        }
    }

    public function test_warning_logged_on_fallback(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'LLM provider failed, attempting fallback'
                    && isset($context['failed_provider'])
                    && isset($context['next_provider']);
            });

        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'Primary',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'Fallback',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);

        $callCount = 0;
        $this->prismMapper->method('generateText')
            ->willReturnCallback(function () use (&$callCount, $mockResponse) {
                $callCount++;
                if ($callCount === 1) {
                    throw PrismRateLimitedException::make();
                }

                return $mockResponse;
            });

        $this->executor->executeWithFallback($tenant, [], $provider1);
    }

    public function test_debug_ephemeral_dispatched_when_debug_enabled(): void
    {
        Bus::fake([SendEphemeralSqlDebug::class]);

        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'Primary',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'Fallback',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'slack_user_id' => $slackUser->id,
        ]);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);
        $this->settingService->method('isEnabled')
            ->willReturn(true);

        $callCount = 0;
        $this->prismMapper->method('generateText')
            ->willReturnCallback(function () use (&$callCount, $mockResponse) {
                $callCount++;
                if ($callCount === 1) {
                    throw PrismRateLimitedException::make();
                }

                return $mockResponse;
            });

        $this->executor->executeWithFallback($tenant, [], $provider1, $query);

        Bus::assertDispatched(SendEphemeralSqlDebug::class);
    }

    public function test_no_debug_when_debug_disabled(): void
    {
        Bus::fake([SendEphemeralSqlDebug::class]);

        $tenant = Tenant::factory()->create();
        $provider1 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 0,
            'name' => 'Primary',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 1,
            'name' => 'Fallback',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $thread = Thread::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $slackUser = SlackUser::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        $query = Query::factory()->create([
            'thread_id' => $thread->id,
            'slack_user_id' => $slackUser->id,
        ]);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider1, $provider2]);
        $this->settingService->method('isEnabled')
            ->willReturn(false);

        $callCount = 0;
        $this->prismMapper->method('generateText')
            ->willReturnCallback(function () use (&$callCount, $mockResponse) {
                $callCount++;
                if ($callCount === 1) {
                    throw PrismRateLimitedException::make();
                }

                return $mockResponse;
            });

        $this->executor->executeWithFallback($tenant, [], $provider1, $query);

        Bus::assertNotDispatched(SendEphemeralSqlDebug::class);
    }

    public function test_primary_provider_is_always_first(): void
    {
        $tenant = Tenant::factory()->create();
        // Provider2 has lower sort but provider1 is passed as primary
        $provider1 = LlmProvider::factory()->forTenant($tenant)->anthropic()->create([
            'sort' => 2,
            'name' => 'Primary',
        ]);
        $provider2 = LlmProvider::factory()->forTenant($tenant)->openai()->create([
            'sort' => 1,
            'name' => 'Other',
        ]);
        $this->createFallbackSetting($tenant, 3);

        $mockResponse = $this->createStub(Response::class);

        $this->providerResolver->method('resolveAll')
            ->willReturn([$provider2, $provider1]);

        $triedProviderIds = [];
        $this->prismMapper->method('generateText')
            ->willReturnCallback(function (LlmProvider $p) use (&$triedProviderIds, $mockResponse) {
                $triedProviderIds[] = $p->id;
                if (count($triedProviderIds) === 1) {
                    throw PrismRateLimitedException::make();
                }

                return $mockResponse;
            });

        $result = $this->executor->executeWithFallback($tenant, [], $provider1);

        // Primary should be tried first, even though it has higher sort
        $this->assertSame($provider1->id, $triedProviderIds[0]);
        $this->assertSame($provider2->id, $triedProviderIds[1]);
    }

    /**
     * Helper to create the FALLBACK_ATTEMPTS tenant setting.
     */
    private function createFallbackSetting(Tenant $tenant, int $attempts): void
    {
        TenantSetting::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => TenantSettingEnum::FALLBACK_ATTEMPTS,
            'value' => (string) $attempts,
        ]);
    }
}
