<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenant;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Payloads\TenantCollectionPayload;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all tenants.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $query = Tenant::query();

        $user = $request->user('admin');

        if ($user instanceof User && $user->level === UserLevel::TENANT) {
            $query->where('id', $user->tenant_id);
        }

        $tenants = $query
            ->orderBy('created_at', 'desc')
            ->get();

        $payload = new TenantCollectionPayload($tenants->all());

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
