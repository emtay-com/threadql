<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\TenantSetting;

use App\Enums\TenantSettingEnum;
use App\Http\Controllers\Controller;
use App\Http\Payloads\TenantSettingCollectionPayload;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all tenant settings, creating any missing ones with defaults.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $settings = [];

        foreach (TenantSettingEnum::cases() as $case) {
            $settings[] = $tenant->getSetting($case);
        }

        $payload = new TenantSettingCollectionPayload($settings);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
