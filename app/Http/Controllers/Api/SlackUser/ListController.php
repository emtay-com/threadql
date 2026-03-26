<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SlackUser;

use App\Http\Controllers\Controller;
use App\Http\Payloads\SlackUserCollectionPayload;
use App\Models\SlackUser;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all Slack users for a tenant.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $slackUsers = SlackUser::query()
            ->where('tenant_id', $tenant->id)
            ->withTrashed()
            ->orderBy('created_at', 'desc')
            ->get();

        /** @var array<int, SlackUser> $slackUserItems */
        $slackUserItems = $slackUsers->all();

        $payload = new SlackUserCollectionPayload($slackUserItems);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
