<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Payloads\UserCollectionPayload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all admin users.
     */
    public function __invoke(): JsonResponse
    {
        $users = User::query()
            ->with('tenant')
            ->orderBy('created_at', 'desc')
            ->get();

        $payload = new UserCollectionPayload($users->all());

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
