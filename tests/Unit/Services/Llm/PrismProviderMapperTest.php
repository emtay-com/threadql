<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Services\Llm\PrismProviderMapper;
use App\Services\Llm\ProviderOptionsResolver;
use InvalidArgumentException;
use Mockery\MockInterface;
use Prism\Prism\Enums\Provider;
use Prism\Relay\Exceptions\ToolDefinitionException;
use Prism\Relay\RelayFactory;
use Tests\TestCase;

class PrismProviderMapperTest extends TestCase
{
    private PrismProviderMapper $mapper;

    private MockInterface&RelayFactory $relayFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->relayFactory = \Mockery::mock(RelayFactory::class);
        $this->mapper = new PrismProviderMapper(new ProviderOptionsResolver, $this->relayFactory);
    }

    public function test_it_maps_openai_adapter_to_provider(): void
    {
        $provider = $this->mapper->mapAdapterToProvider('openai');
        $this->assertEquals(Provider::OpenAI, $provider);
    }

    public function test_it_maps_anthropic_adapter_to_provider(): void
    {
        $provider = $this->mapper->mapAdapterToProvider('anthropic');
        $this->assertEquals(Provider::Anthropic, $provider);
    }

    public function test_it_maps_groq_adapter_to_provider(): void
    {
        $provider = $this->mapper->mapAdapterToProvider('groq');
        $this->assertEquals(Provider::Groq, $provider);
    }

    public function test_it_throws_exception_for_unsupported_adapter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported adapter: unsupported');

        $this->mapper->mapAdapterToProvider('unsupported');
    }

    public function test_it_returns_default_model_for_openai(): void
    {
        $model = $this->mapper->getDefaultModel('openai');
        $this->assertEquals('gpt-4o', $model);
    }

    public function test_it_returns_default_model_for_anthropic(): void
    {
        $model = $this->mapper->getDefaultModel('anthropic');
        $this->assertEquals('claude-3-5-sonnet-20241022', $model);
    }

    public function test_it_throws_exception_for_unsupported_adapter_in_default_model(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No default model for adapter: unsupported');

        $this->mapper->getDefaultModel('unsupported');
    }

    public function test_fetch_relay_tools_returns_tools_on_first_attempt(): void
    {
        $expectedTools = ['tool1', 'tool2'];

        $this->relayFactory->shouldReceive('tools')
            ->once()
            ->with('app')
            ->andReturn($expectedTools);

        $result = $this->mapper->fetchRelayTools();

        $this->assertEquals($expectedTools, $result);
    }

    public function test_fetch_relay_tools_retries_on_tool_definition_exception(): void
    {
        $expectedTools = ['tool1', 'tool2'];
        $callCount = 0;

        $this->relayFactory->shouldReceive('tools')
            ->times(2)
            ->with('app')
            ->andReturnUsing(function () use (&$callCount, $expectedTools) {
                $callCount++;
                if ($callCount === 1) {
                    throw new ToolDefinitionException('Connection refused');
                }

                return $expectedTools;
            });

        $result = $this->mapper->fetchRelayTools();

        $this->assertEquals($expectedTools, $result);
    }

    public function test_fetch_relay_tools_throws_after_all_retries_exhausted(): void
    {
        $this->relayFactory->shouldReceive('tools')
            ->times(3)
            ->with('app')
            ->andThrow(new ToolDefinitionException('MCP container down'));

        $this->expectException(ToolDefinitionException::class);
        $this->expectExceptionMessage('MCP container down');

        $this->mapper->fetchRelayTools();
    }
}
