<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\TenantSetting;

use App\Enums\TenantSettingEnum;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PutController extends Controller
{
    /**
     * Batch update tenant settings.
     */
    public function __invoke(Request $request, Tenant $tenant): Response
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.name' => 'required|string',
            'settings.*.value' => 'required|string|max:255',
        ]);

        foreach ($validated['settings'] as $settingData) {
            $enum = TenantSettingEnum::tryFrom($settingData['name']);
            if ($enum === null) {
                continue;
            }

            $setting = $tenant->getSetting($enum);
            $setting->value = $settingData['value'];
            $setting->save();
        }

        return response()->noContent();
    }
}
