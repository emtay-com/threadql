<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\LlmProvider;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test LLM provider model encryption functionality
 */
class LlmProviderEncryptionTest extends TestCase
{
    /**
     * Test that API key is properly encrypted and decrypted
     */
    public function test_api_key_is_encrypted_and_decrypted(): void
    {
        $plainApiKey = 'sk-1234567890abcdefghijklmnopqrstuvwxyz';

        // Create provider with plain API key
        $provider = LlmProvider::factory()->create([
            'api_key' => $plainApiKey,
        ]);

        // Verify that reading back returns the same plaintext
        $this->assertEquals($plainApiKey, $provider->api_key);

        // Verify that raw DB value is encrypted (different from plaintext)
        $rawData = DB::table('llm_providers')->where('id', $provider->id)->first();

        $this->assertNotEquals($plainApiKey, $rawData->api_key);

        // Verify that encrypted value is not empty
        $this->assertNotEmpty($rawData->api_key);
    }

    /**
     * Test that null API key is handled correctly
     */
    public function test_null_api_key_is_handled_correctly(): void
    {
        $provider = LlmProvider::factory()->create([
            'api_key' => null,
        ]);

        $this->assertNull($provider->api_key);

        // Verify raw DB value is also null
        $rawData = DB::table('llm_providers')->where('id', $provider->id)->first();
        $this->assertNull($rawData->api_key);
    }

    /**
     * Test that API key can be updated
     */
    public function test_api_key_can_be_updated(): void
    {
        $provider = LlmProvider::factory()->create([
            'api_key' => 'original-api-key-12345',
        ]);

        $newApiKey = 'new-api-key-67890';
        $provider->api_key = $newApiKey;
        $provider->save();

        $provider->refresh();

        $this->assertEquals($newApiKey, $provider->api_key);

        // Verify the raw DB value changed and is still encrypted
        $rawData = DB::table('llm_providers')->where('id', $provider->id)->first();
        $this->assertNotEquals($newApiKey, $rawData->api_key);
    }

    /**
     * Test that fresh model retrieval still decrypts correctly
     */
    public function test_fresh_model_retrieval_decrypts_correctly(): void
    {
        $plainApiKey = 'test-api-key-for-fresh-retrieval';

        $provider = LlmProvider::factory()->create([
            'api_key' => $plainApiKey,
        ]);

        // Retrieve fresh from database
        $freshProvider = LlmProvider::find($provider->id);

        $this->assertEquals($plainApiKey, $freshProvider->api_key);
    }
}
