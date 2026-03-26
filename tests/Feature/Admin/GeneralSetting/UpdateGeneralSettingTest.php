<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\GeneralSetting;

use App\Enums\SettingEnum;
use App\Models\GeneralSetting;
use App\Models\MasterAdmin;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class UpdateGeneralSettingTest extends TestCase
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
        $response = $this->putJson('/api/admin/settings', [
            'settings' => [
                [
                    'setting' => 'max_rows_inline_csv',
                    'value' => '200',
                ],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_it_updates_an_existing_setting(): void
    {
        GeneralSetting::factory()->create([
            'setting' => SettingEnum::MAX_ROWS_INLINE_CSV,
            'value' => '100',
        ]);

        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'max_rows_inline_csv',
                        'value' => '200',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_rows_inline_csv',
            'value' => '200',
        ]);
    }

    public function test_it_updates_multiple_settings(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'max_rows_inline_csv',
                        'value' => '500',
                    ],
                    [
                        'setting' => 'max_priority_tables',
                        'value' => '50',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_rows_inline_csv',
            'value' => '500',
        ]);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_priority_tables',
            'value' => '50',
        ]);
    }

    public function test_it_creates_setting_with_default_then_updates(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'max_priority_tables',
                        'value' => '50',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_priority_tables',
            'value' => '50',
        ]);
    }

    public function test_it_updates_new_general_settings(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'llm_resume_max_steps',
                        'value' => '12',
                    ],
                    [
                        'setting' => 'start_of_week',
                        'value' => 'sunday',
                    ],
                    [
                        'setting' => 'week_definition',
                        'value' => 'us',
                    ],
                    [
                        'setting' => 'max_tokens',
                        'value' => '128000',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseHas('general_settings', [
            'setting' => 'llm_resume_max_steps',
            'value' => '12',
        ]);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'start_of_week',
            'value' => 'sunday',
        ]);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'week_definition',
            'value' => 'us',
        ]);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_tokens',
            'value' => '128000',
        ]);
    }

    public function test_it_skips_unknown_settings(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'nonexistent_setting',
                        'value' => '123',
                    ],
                    [
                        'setting' => 'max_rows_inline_csv',
                        'value' => '300',
                    ],
                ],
            ]);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('general_settings', [
            'setting' => 'nonexistent_setting',
        ]);
        $this->assertDatabaseHas('general_settings', [
            'setting' => 'max_rows_inline_csv',
            'value' => '300',
        ]);
    }

    public function test_it_validates_settings_is_required(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', []);

        $response->assertStatus(422);
    }

    public function test_it_validates_value_is_required(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'max_rows_inline_csv',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_it_validates_value_max_length(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'max_rows_inline_csv',
                        'value' => str_repeat('a', 256),
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_it_validates_start_of_week_against_supported_values(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'start_of_week',
                        'value' => 'funday',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_it_validates_week_definition_against_supported_values(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'week_definition',
                        'value' => 'european',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }

    public function test_it_validates_numeric_settings_as_whole_numbers(): void
    {
        $masterAdmin = MasterAdmin::instance();
        $token = JWTAuth::fromUser($masterAdmin);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/admin/settings', [
                'settings' => [
                    [
                        'setting' => 'llm_resume_max_steps',
                        'value' => '12.5',
                    ],
                ],
            ]);

        $response->assertStatus(422);
    }
}
