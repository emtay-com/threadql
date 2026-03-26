<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GeneralSetting;

use App\Enums\SettingEnum;
use App\Http\Controllers\Controller;
use App\Http\Payloads\GeneralSettingCollectionPayload;
use App\Models\GeneralSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all general settings, creating any missing ones with defaults.
     */
    public function __invoke(): JsonResponse
    {
        $settings = [];

        foreach (SettingEnum::cases() as $case) {
            $settings[] = GeneralSetting::resolve($case);
        }

        $payload = new GeneralSettingCollectionPayload($settings);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
