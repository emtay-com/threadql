<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\MessageRole;
use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use App\Models\LlmProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\RelayFactory;

class PrismProviderMapper
{
    private const RELAY_MAX_RETRIES = 3;

    private const RELAY_BASE_BACKOFF_MS = 500;

    public function __construct(
        private readonly ProviderOptionsResolver $optionsResolver,
        private readonly RelayFactory $relayFactory,
    ) {
    }

    /**
     * Map our internal adapter to Prism Provider enum
     */
    public function mapAdapterToProvider(string $adapter): Provider
    {
        return match ($adapter) {
            'openai' => Provider::OpenAI,
            'anthropic' => Provider::Anthropic,
            'groq' => Provider::Groq,
            'mistral' => Provider::Mistral,
            'ollama' => Provider::Ollama,
            'gemini' => Provider::Gemini,
            'deepseek' => Provider::DeepSeek,
            'xai' => Provider::XAI,
            'openrouter' => Provider::OpenRouter,
            default => throw new InvalidArgumentException("Unsupported adapter: {$adapter}")
        };
    }

    /**
     * Get the default model for a provider if none is specified
     */
    public function getDefaultModel(string $adapter): string
    {
        return match ($adapter) {
            'openai' => 'gpt-4o',
            'anthropic' => 'claude-3-5-sonnet-20241022',
            'groq' => 'llama3.2-70b-8192',
            'mistral' => 'mistral-large-latest',
            'ollama' => 'llama3.2',
            'gemini' => 'gemini-1.5-flash',
            'deepseek' => 'deepseek-chat',
            'xai' => 'x-1',
            'openrouter' => 'openai/gpt-4o',
            default => throw new InvalidArgumentException("No default model for adapter: {$adapter}")
        };
    }

    /**
     * Generate text using Prism with the given provider and messages
     */
    public function generateText(LlmProvider $provider, array $messages): Response
    {
        $builder = $this->makePrismBuilder($provider, $messages);

        $response = $builder->asText();

        return $response;
    }

    public function makePrismBuilder(LlmProvider $provider, array $messages): PendingRequest
    {
        $prismProvider = $this->mapAdapterToProvider($provider->adapter);
        $modelName = $provider->model_name ?: $this->getDefaultModel($provider->adapter);
        $providerConfig = $this->optionsResolver->buildProviderConfig(
            $provider->api_key,
            $provider->url,
            $provider->options,
        );

        $prismMessages = $this->convertMessagesToPrism($messages);

        /** @var PendingRequest $prismRequest */
        $prismRequest = Prism::text()
            ->using($prismProvider, $modelName, $providerConfig)
            ->withMaxSteps((int) GeneralSetting::resolve(SettingEnum::LLM_RESUME_MAX_STEPS)->value)
            ->withMaxTokens((int) GeneralSetting::resolve(SettingEnum::MAX_TOKENS)->value)
            ->withClientOptions([
                'timeout' => 1440,
                'connect_timeout' => 15,
            ])
            ->withTools([...$this->fetchRelayTools()]);

        if ($prismProvider === Provider::Anthropic) {
            $systemMessage = $prismMessages[MessageRole::SYSTEM->value];
            unset($prismMessages[MessageRole::SYSTEM->value]);
            $prismRequest->withSystemPrompt($systemMessage);
        }

        return $prismRequest->withMessages(array_values($prismMessages));
    }

    /**
     * Fetch MCP tool definitions from Relay with retry logic
     */
    public function fetchRelayTools(): array
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= self::RELAY_MAX_RETRIES; $attempt++) {
            try {
                return $this->relayFactory->tools('app');
            } catch (ToolDefinitionException $e) {
                $lastException = $e;

                if ($attempt < self::RELAY_MAX_RETRIES) {
                    $backoffMs = self::RELAY_BASE_BACKOFF_MS * $attempt;

                    Log::warning('Relay tool fetch failed, retrying', [
                        'attempt' => $attempt,
                        'max_retries' => self::RELAY_MAX_RETRIES,
                        'backoff_ms' => $backoffMs,
                        'error' => $e->getMessage(),
                    ]);

                    usleep($backoffMs * 1000);
                }
            }
        }

        Log::error('Relay tool fetch failed after all retries', [
            'max_retries' => self::RELAY_MAX_RETRIES,
            'error' => $lastException->getMessage(),
        ]);

        throw $lastException;
    }

    /**
     * Convert our message format to Prism message objects
     */
    public function convertMessagesToPrism(array $messages): array
    {
        $prismMessages = [];

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            $messageRole = MessageRole::tryFrom($role);
            if (! $messageRole) {
                throw new InvalidArgumentException("Unsupported message role: {$role}");
            }

            $prismMessages[$messageRole->value] = match ($messageRole) {
                MessageRole::SYSTEM => new SystemMessage($content),
                MessageRole::USER => new UserMessage($content),
                MessageRole::ASSISTANT => $this->createAssistantMessage($message),
                MessageRole::TOOL => $this->createToolResultMessage($message),
            };
        }

        return $prismMessages;
    }

    /**
     * Create an AssistantMessage with proper tool calls
     */
    private function createAssistantMessage(array $message): AssistantMessage
    {
        $content = $message['content'] ?? '';
        $toolCalls = [];

        if (isset($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $toolCallData) {
                if (isset($toolCallData['function'])) {
                    $function = $toolCallData['function'];
                    $toolCalls[] = new ToolCall(
                        id: $toolCallData['function_call_id'] ?? 'unknown',
                        name: $function['name'] ?? 'unknown',
                        arguments: $function['arguments'] ?? [],
                        resultId: $toolCallData['call_id'],
                    );
                }
            }
        }

        return new AssistantMessage($content, $toolCalls);
    }

    /**
     * Create a ToolResultMessage from a tool message
     */
    private function createToolResultMessage(array $message): ToolResultMessage
    {
        $toolCallId = $message['tool_call_id'] ?? 'unknown';
        $content = $message['content'];

        // For tool results, we create a ToolResult with the content as the result
        $toolResult = new ToolResult(
            toolCallId: $toolCallId,
            toolName: $message['tool_name'],
            args: [], // We don't have the original args
            result: $content,
            toolCallResultId: $message['call_id'] ?? 'unknown',
        );

        return new ToolResultMessage([$toolResult]);
    }
}
