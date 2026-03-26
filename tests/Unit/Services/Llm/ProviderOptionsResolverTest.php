<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Llm;

use App\Services\Llm\ProviderOptionsResolver;
use PHPUnit\Framework\TestCase;

class ProviderOptionsResolverTest extends TestCase
{
    private ProviderOptionsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProviderOptionsResolver;
    }

    public function test_it_returns_options_for_openai(): void
    {
        $options = $this->resolver->getOptionsForAdapter('openai');

        $this->assertArrayHasKey('organization', $options);
        $this->assertArrayHasKey('project', $options);
        $this->assertEquals('string', $options['organization']['type']);
        $this->assertNull($options['organization']['default']);
    }

    public function test_it_returns_options_for_anthropic(): void
    {
        $options = $this->resolver->getOptionsForAdapter('anthropic');

        $this->assertArrayHasKey('version', $options);
        $this->assertArrayHasKey('default_thinking_budget', $options);
        $this->assertArrayHasKey('anthropic_beta', $options);
        $this->assertEquals('2023-06-01', $options['version']['default']);
        $this->assertEquals('number', $options['default_thinking_budget']['type']);
    }

    public function test_it_returns_empty_options_for_ollama(): void
    {
        $options = $this->resolver->getOptionsForAdapter('ollama');

        $this->assertEmpty($options);
    }

    public function test_it_returns_empty_options_for_unknown_adapter(): void
    {
        $options = $this->resolver->getOptionsForAdapter('unknown');

        $this->assertEmpty($options);
    }

    public function test_it_returns_all_adapter_options(): void
    {
        $all = $this->resolver->getAllAdapterOptions();

        $this->assertArrayHasKey('openai', $all);
        $this->assertArrayHasKey('anthropic', $all);
        $this->assertArrayHasKey('ollama', $all);
        $this->assertArrayHasKey('gemini', $all);
        $this->assertArrayHasKey('deepseek', $all);
        $this->assertArrayHasKey('xai', $all);
        $this->assertArrayHasKey('groq', $all);
        $this->assertArrayHasKey('mistral', $all);
    }

    public function test_it_returns_supported_adapters(): void
    {
        $adapters = $this->resolver->getSupportedAdapters();

        $this->assertContains('openai', $adapters);
        $this->assertContains('anthropic', $adapters);
        $this->assertContains('ollama', $adapters);
        $this->assertContains('gemini', $adapters);
    }

    public function test_it_builds_provider_config_with_all_values(): void
    {
        $config = $this->resolver->buildProviderConfig(
            'sk-test-key',
            'https://api.openai.com/v1',
            [
                'organization' => 'org-123',
                'project' => 'proj-456',
            ],
        );

        $this->assertEquals([
            'api_key' => 'sk-test-key',
            'url' => 'https://api.openai.com/v1',
            'organization' => 'org-123',
            'project' => 'proj-456',
        ], $config);
    }

    public function test_it_builds_provider_config_without_api_key(): void
    {
        $config = $this->resolver->buildProviderConfig(null, 'http://localhost:11434', null);

        $this->assertEquals([
            'url' => 'http://localhost:11434',
        ], $config);
    }

    public function test_it_builds_provider_config_with_empty_strings(): void
    {
        $config = $this->resolver->buildProviderConfig('', '', null);

        $this->assertEquals([], $config);
    }

    public function test_it_builds_provider_config_with_only_api_key(): void
    {
        $config = $this->resolver->buildProviderConfig('sk-key', null, null);

        $this->assertEquals([
            'api_key' => 'sk-key',
        ], $config);
    }

    public function test_it_merges_options_into_config(): void
    {
        $config = $this->resolver->buildProviderConfig(
            'sk-key',
            null,
            [
                'version' => '2024-01-01',
                'default_thinking_budget' => '2048',
            ],
        );

        $this->assertEquals([
            'api_key' => 'sk-key',
            'version' => '2024-01-01',
            'default_thinking_budget' => '2048',
        ], $config);
    }

    public function test_it_strips_reserved_keys_from_options(): void
    {
        $config = $this->resolver->buildProviderConfig(
            'sk-real-key',
            'https://real-url.com',
            [
                'api_key' => 'attacker-key',
                'url' => 'https://evil.com',
                'organization' => 'org-legit',
            ],
        );

        $this->assertEquals('sk-real-key', $config['api_key']);
        $this->assertEquals('https://real-url.com', $config['url']);
        $this->assertEquals('org-legit', $config['organization']);
        $this->assertCount(3, $config);
    }

    public function test_it_strips_reserved_url_key_from_options_when_no_url_column(): void
    {
        $config = $this->resolver->buildProviderConfig(
            'sk-key',
            null,
            [
                'url' => 'https://evil.com',
                'version' => '2024-01-01',
            ],
        );

        $this->assertArrayNotHasKey('url', $config);
        $this->assertEquals('2024-01-01', $config['version']);
    }
}
