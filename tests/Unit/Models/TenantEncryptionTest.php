<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test tenant model encryption functionality
 */
class TenantEncryptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test that Slack credentials are properly encrypted and decrypted
     */
    public function test_slack_credentials_are_encrypted_and_decrypted(): void
    {
        $plainAppId = 'A0123456789';
        $plainClientId = '123456789.987654321';
        $plainBotToken = 'xoxb-123456789-123456789-abcdefghijklmnopqrstuvwx';
        $plainSigningSecret = 'abcdef1234567890abcdef1234567890';
        $plainVerificationToken = 'abcdefghijklmnopqrstuvwx';

        // Create tenant with plain credentials
        $tenant = Tenant::factory()->create([
            'slack_app_id' => $plainAppId,
            'slack_client_id' => $plainClientId,
            'slack_bot_token' => $plainBotToken,
            'slack_signing_secret' => $plainSigningSecret,
            'slack_verification_token' => $plainVerificationToken,
        ]);

        // Verify that reading back returns the same plaintext
        $this->assertEquals($plainAppId, $tenant->slack_app_id);
        $this->assertEquals($plainClientId, $tenant->slack_client_id);
        $this->assertEquals($plainBotToken, $tenant->slack_bot_token);
        $this->assertEquals($plainSigningSecret, $tenant->slack_signing_secret);
        $this->assertEquals($plainVerificationToken, $tenant->slack_verification_token);

        // Verify that raw DB values are encrypted (different from plaintext)
        $rawData = DB::table('tenants')->where('id', $tenant->id)->first();

        $this->assertNotEquals($plainAppId, $rawData->slack_app_id);
        $this->assertNotEquals($plainClientId, $rawData->slack_client_id);
        $this->assertNotEquals($plainBotToken, $rawData->slack_bot_token);
        $this->assertNotEquals($plainSigningSecret, $rawData->slack_signing_secret);
        $this->assertNotEquals($plainVerificationToken, $rawData->slack_verification_token);

        // Verify that encrypted values are not empty
        $this->assertNotEmpty($rawData->slack_app_id);
        $this->assertNotEmpty($rawData->slack_client_id);
        $this->assertNotEmpty($rawData->slack_bot_token);
        $this->assertNotEmpty($rawData->slack_signing_secret);
        $this->assertNotEmpty($rawData->slack_verification_token);
    }

    /**
     * Test that null values are handled correctly
     */
    public function test_null_slack_credentials_are_handled_correctly(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_app_id' => null,
            'slack_client_id' => null,
            'slack_bot_token' => null,
            'slack_signing_secret' => null,
            'slack_verification_token' => null,
        ]);

        $this->assertNull($tenant->slack_app_id);
        $this->assertNull($tenant->slack_client_id);
        $this->assertNull($tenant->slack_bot_token);
        $this->assertNull($tenant->slack_signing_secret);
        $this->assertNull($tenant->slack_verification_token);
    }

    /**
     * Test that credentials can be updated
     */
    public function test_slack_credentials_can_be_updated(): void
    {
        $tenant = Tenant::factory()->create([
            'slack_app_id' => 'A0123456789',
        ]);

        $newAppId = 'A0987654321';
        $tenant->slack_app_id = $newAppId;
        $tenant->save();

        $tenant->refresh();

        $this->assertEquals($newAppId, $tenant->slack_app_id);
    }
}
