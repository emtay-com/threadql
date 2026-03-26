<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Simple ping endpoint to verify tenant connectivity.
 */
class PingController extends Controller
{
    /**
     * Return a simple ping response to verify tenant exists.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        return new JsonResponse([
            'ping' => true,
        ], Response::HTTP_OK);
    }
}
