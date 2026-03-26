<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Payloads;

use App\Http\Payloads\LlmProviderPayload;
use App\Models\LlmProvider;
use Tests\TestCase;

class LlmProviderPayloadTest extends TestCase
{
    public function test_it_serializes_provider_with_api_key(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'OpenAI',
            'adapter' => 'openai',
            'url' => 'https://api.openai.com/v1',
            'model_name' => 'gpt-4',
            'api_key' => 'sk-test-key',
            'enabled' => true,
            'sort' => 0,
        ]);

        $payload = new LlmProviderPayload($provider);
        $result = $payload->jsonSerialize();

        $this->assertArrayHasKey('data', $result);
        $data = $result['data'];

        $this->assertEquals($provider->id, $data['id']);
        $this->assertEquals('OpenAI', $data['name']);
        $this->assertEquals('openai', $data['adapter']);
        $this->assertEquals('https://api.openai.com/v1', $data['url']);
        $this->assertEquals('gpt-4', $data['model']);
        $this->assertTrue($data['has_api_key']);
        $this->assertTrue($data['enabled']);
        $this->assertEquals(0, $data['sort']);
        $this->assertNotNull($data['created_at']);
        $this->assertNotNull($data['updated_at']);
    }

    public function test_it_serializes_provider_without_api_key(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'Ollama',
            'adapter' => 'ollama',
            'url' => 'http://localhost:11434',
            'model_name' => 'llama2',
            'api_key' => null,
        ]);

        $payload = new LlmProviderPayload($provider);
        $result = $payload->jsonSerialize();

        $data = $result['data'];

        $this->assertEquals($provider->id, $data['id']);
        $this->assertEquals('Ollama', $data['name']);
        $this->assertFalse($data['has_api_key']);
    }

    public function test_it_serializes_enabled_and_sort(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'Disabled Provider',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'enabled' => false,
            'sort' => 5,
        ]);

        $payload = new LlmProviderPayload($provider);
        $data = $payload->toArray();

        $this->assertFalse($data['enabled']);
        $this->assertEquals(5, $data['sort']);
    }

    public function test_to_array_returns_flat_data_without_wrapper(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'Anthropic',
            'adapter' => 'anthropic',
            'url' => 'https://api.anthropic.com',
            'model_name' => 'claude-3',
            'api_key' => 'sk-ant-key',
        ]);

        $payload = new LlmProviderPayload($provider);
        $result = $payload->toArray();

        $this->assertArrayNotHasKey('data', $result);
        $this->assertEquals($provider->id, $result['id']);
        $this->assertEquals('Anthropic', $result['name']);
        $this->assertEquals('anthropic', $result['adapter']);
        $this->assertEquals('https://api.anthropic.com', $result['url']);
        $this->assertEquals('claude-3', $result['model']);
        $this->assertTrue($result['has_api_key']);
    }

    public function test_it_serializes_options_when_present(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'OpenAI with options',
            'adapter' => 'openai',
            'model_name' => 'gpt-4',
            'options' => [
                'organization' => 'org-123',
                'project' => 'proj-456',
            ],
        ]);

        $payload = new LlmProviderPayload($provider);
        $data = $payload->toArray();

        $this->assertArrayHasKey('options', $data);
        $this->assertEquals([
            'organization' => 'org-123',
            'project' => 'proj-456',
        ], $data['options']);
    }

    public function test_it_serializes_null_options(): void
    {
        $provider = LlmProvider::factory()->create([
            'name' => 'No options',
            'adapter' => 'ollama',
            'model_name' => 'llama2',
            'options' => null,
        ]);

        $payload = new LlmProviderPayload($provider);
        $data = $payload->toArray();

        $this->assertArrayHasKey('options', $data);
        $this->assertNull($data['options']);
    }
}
