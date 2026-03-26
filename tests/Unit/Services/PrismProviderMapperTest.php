<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\MessageRole;
use App\Models\LlmProvider;
use App\Services\Llm\PrismProviderMapper;
use App\Services\Llm\ProviderOptionsResolver;
use InvalidArgumentException;
use Mockery\MockInterface;
use Prism\Prism\ValueObjects\Tool;
use Prism\Relay\RelayFactory;
use Tests\TestCase;

/**
 * Test the PrismProviderMapper functionality
 */
class PrismProviderMapperTest extends TestCase
{
    private PrismProviderMapper $mapper;

    private LlmProvider $provider;

    private MockInterface&RelayFactory $relayFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->relayFactory = \Mockery::mock(RelayFactory::class);
        $this->mapper = new PrismProviderMapper(new ProviderOptionsResolver, $this->relayFactory);
        $this->provider = new LlmProvider([
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'api_key' => null,
            'url' => null,
            'options' => null,
        ]);
    }

    /**
     * Test convertMessagesToPrism handles all supported message roles
     */
    public function test_convert_messages_to_prism_handles_all_supported_roles(): void
    {
        $messages = [
            [
                'role' => MessageRole::SYSTEM->value,
                'content' => 'You are a helpful assistant',
            ],
            [
                'role' => MessageRole::USER->value,
                'content' => 'Hello',
            ],
            [
                'role' => MessageRole::ASSISTANT->value,
                'content' => 'Hi there!',
            ],
            [
                'role' => MessageRole::TOOL->value,
                'content' => 'Tool result',
                'tool_call_id' => 'call_123',
                'tool_name' => 'refresh_token',
            ],
        ];

        $prismMessages = array_values($this->mapper->convertMessagesToPrism($messages));

        $this->assertCount(4, $prismMessages);
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\SystemMessage', get_class($prismMessages[0]));
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\UserMessage', get_class($prismMessages[1]));
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\AssistantMessage', get_class($prismMessages[2]));
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\ToolResultMessage', get_class($prismMessages[3]));
    }

    /**
     * Test convertMessagesToPrism throws exception for unsupported role
     */
    public function test_convert_messages_to_prism_throws_exception_for_unsupported_role(): void
    {
        $messages = [
            [
                'role' => 'unsupported_role',
                'content' => 'Test content',
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message role: unsupported_role');

        $this->mapper->convertMessagesToPrism($messages);
    }

    /**
     * Test convertMessagesToPrism handles assistant messages correctly
     */
    public function test_convert_messages_to_prism_handles_assistant_messages(): void
    {
        $messages = [
            [
                'role' => MessageRole::ASSISTANT->value,
                'content' => 'I am an AI assistant',
            ],
        ];

        $prismMessages = array_values($this->mapper->convertMessagesToPrism($messages));

        $this->assertCount(1, $prismMessages);
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\AssistantMessage', get_class($prismMessages[0]));
        $this->assertEquals('I am an AI assistant', $prismMessages[0]->content);
        $this->assertEmpty($prismMessages[0]->toolCalls);
    }

    /**
     * Test convertMessagesToPrism handles tool messages correctly
     */
    public function test_convert_messages_to_prism_handles_tool_messages(): void
    {
        $messages = [
            [
                'role' => MessageRole::TOOL->value,
                'content' => 'Tool execution result',
                'tool_call_id' => 'call_456',
                'tool_name' => 'refresh_token',
            ],
        ];

        $prismMessages = array_values($this->mapper->convertMessagesToPrism($messages));

        $this->assertCount(1, $prismMessages);
        $this->assertEquals('Prism\Prism\ValueObjects\Messages\ToolResultMessage', get_class($prismMessages[0]));
        $this->assertCount(1, $prismMessages[0]->toolResults);
        $this->assertEquals('Tool execution result', $prismMessages[0]->toolResults[0]->result);
    }

    /**
     * Test makePrismBuilder uses convertMessagesToPrism internally
     */
    public function test_make_prism_builder_uses_convert_messages_to_prism(): void
    {
        // Mock the RelayFactory to prevent live environment dependency
        $this->relayFactory->shouldReceive('tools')
            ->with('app')
            ->andReturn([]);

        $messages = [
            [
                'role' => MessageRole::USER->value,
                'content' => 'Test message',
            ],
        ];

        $builder = $this->mapper->makePrismBuilder($this->provider, $messages);

        $this->assertNotNull($builder);
        // The builder should have been created successfully with the converted messages
    }
}
