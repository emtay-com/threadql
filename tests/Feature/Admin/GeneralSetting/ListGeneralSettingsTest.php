<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\GeneralSetting;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use App\Models\MasterAdmin;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class ListGeneralSettingsTest extends TestCase
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
        $response = $this->getJson('/api/admin/settings');

        $response->assertStatus(401);
    }

    public function test_it_lists_all_settings_with_defaults(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'setting', 'value', 'created_at', 'updated_at'],
            ],
            'meta' => ['total'],
        ]);

        $data = $response->json('data');
        $this->assertCount(count(SettingEnum::cases()), $data);

        $settings = array_column($data, 'setting');
        foreach (SettingEnum::cases() as $case) {
            $this->assertContains($case->value, $settings);
        }

        $this->assertEquals(count(SettingEnum::cases()), $response->json('meta.total'));
    }

    public function test_it_creates_missing_settings_with_defaults(): void
    {
        $this->assertDatabaseMissing('general_settings', [
            'setting' => 'max_rows_inline_csv',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/settings');

        foreach (SettingEnum::cases() as $case) {
            $this->assertDatabaseHas('general_settings', [
                'setting' => $case->value,
            ]);
        }
    }

    public function test_it_returns_existing_settings_without_overwriting(): void
    {
        GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '500',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/settings');

        $response->assertStatus(200);

        $data = $response->json('data');
        $maxRows = collect($data)
            ->firstWhere('setting', 'max_rows_inline_csv');
        $this->assertEquals('500', $maxRows['value']);
    }

    public function test_it_returns_default_values_for_new_settings(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/settings');

        $data = $response->json('data');

        $maxRows = collect($data)
            ->firstWhere('setting', 'max_rows_inline_csv');
        $this->assertEquals('1000', $maxRows['value']);

        $maxPriority = collect($data)
            ->firstWhere('setting', 'max_priority_tables');
        $this->assertEquals('20', $maxPriority['value']);

        $resumeMaxSteps = collect($data)
            ->firstWhere('setting', 'llm_resume_max_steps');
        $this->assertEquals('10', $resumeMaxSteps['value']);

        $startOfWeek = collect($data)
            ->firstWhere('setting', 'start_of_week');
        $this->assertEquals('monday', $startOfWeek['value']);

        $weekDefinition = collect($data)
            ->firstWhere('setting', 'week_definition');
        $this->assertEquals('iso', $weekDefinition['value']);

        $maxTokens = collect($data)
            ->firstWhere('setting', 'max_tokens');
        $this->assertEquals('64000', $maxTokens['value']);
    }
}
