<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Settings;
use App\Enums\TenantSettingEnum;
use App\Exceptions\AllProvidersExhaustedException;
use App\Infrastructure\Slack\SlackUserSettingService;
use App\Jobs\SendEphemeralSqlDebug;
use App\Models\LlmProvider;
use App\Models\Query;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Text\Response;
use Throwable;

/**
 * Executes LLM calls with automatic fallback to alternative providers on failure.
 */
class LlmFallbackExecutor
{
    public function __construct(
        private readonly PrismProviderMapper $prismMapper,
        private readonly LlmProviderResolver $providerResolver,
        private readonly FallbackExceptionClassifier $classifier,
        private readonly SlackUserSettingService $settingService,
    ) {
    }

    /**
     * Execute an LLM call with fallback to alternative providers.
     *
     * @param Tenant $tenant The tenant making the request
     * @param array $messages The messages to send to the LLM
     * @param LlmProvider $primaryProvider The primary provider to try first
     * @param Query|null $query Optional query for debug notifications
     * @return array{response: Response, provider: LlmProvider}
     */
    public function executeWithFallback(
        Tenant $tenant,
        array $messages,
        LlmProvider $primaryProvider,
        ?Query $query = null
    ): array {
        $maxAttempts = (int) $tenant->getSetting(TenantSettingEnum::FALLBACK_ATTEMPTS)->value;
        $allProviders = $this->providerResolver->resolveAll($tenant);
        $providers = $this->buildProviderList($allProviders, $primaryProvider, $maxAttempts);

        $providersTried = [];
        $lastException = null;

        foreach ($providers as $index => $provider) {
            try {
                $response = $this->prismMapper->generateText($provider, $messages);

                return [
                    'response' => $response,
                    'provider' => $provider,
                ];
            } catch (Throwable $e) {
                $providersTried[] = $provider;
                $lastException = $e;

                if (! $this->classifier->isFallbackable($e)) {
                    throw $e;
                }

                $nextProvider = $providers[$index + 1] ?? null;

                Log::warning('LLM provider failed, attempting fallback', [
                    'failed_provider' => $provider->name,
                    'failed_adapter' => $provider->adapter,
                    'failed_model' => $provider->model_name,
                    'error' => $e->getMessage(),
                    'next_provider' => $nextProvider?->name,
                    'attempt' => $index + 1,
                    'max_attempts' => count($providers),
                ]);

                if ($nextProvider) {
                    $this->maybeSendDebugNotification($query, $provider, $nextProvider, $e);
                }
            }
        }

        throw new AllProvidersExhaustedException($providersTried, $lastException);
    }

    /**
     * Build the ordered list of providers to try, with the primary provider first.
     *
     * @param LlmProvider[] $allProviders All available providers
     * @param LlmProvider $primaryProvider The preferred primary provider
     * @param int $maxAttempts Maximum number of providers to try
     * @return LlmProvider[]
     */
    private function buildProviderList(array $allProviders, LlmProvider $primaryProvider, int $maxAttempts): array
    {
        // Put primary provider first, then the rest (excluding primary to avoid duplicates)
        $providers = [$primaryProvider];

        foreach ($allProviders as $provider) {
            if ($provider->id !== $primaryProvider->id) {
                $providers[] = $provider;
            }
        }

        return array_slice($providers, 0, max(1, $maxAttempts));
    }

    /**
     * Send a debug ephemeral notification if the user has debug mode enabled.
     */
    private function maybeSendDebugNotification(
        ?Query $query,
        LlmProvider $failedProvider,
        LlmProvider $nextProvider,
        Throwable $error
    ): void {
        if (! $query) {
            return;
        }

        $slackUser = $query->slackUser;
        if (! $slackUser) {
            return;
        }

        if (! $this->settingService->isEnabled($slackUser, Settings::DEBUG->value)) {
            return;
        }

        $thread = $query->thread;
        if (! $thread) {
            return;
        }

        $text = sprintf(
            "⚠ LLM fallback: `%s` (%s/%s) failed: %s\nTrying: `%s` (%s/%s)",
            $failedProvider->name,
            $failedProvider->adapter,
            $failedProvider->model_name ?: 'default',
            $error->getMessage(),
            $nextProvider->name,
            $nextProvider->adapter,
            $nextProvider->model_name ?: 'default',
        );

        dispatch(new SendEphemeralSqlDebug(
            queryId: $query->id,
            channelId: $thread->channel_id,
            userId: $slackUser->slack_user_id,
            text: $text,
        ));
    }
}
