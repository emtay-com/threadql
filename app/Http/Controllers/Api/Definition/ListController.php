<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Definition;

use App\Http\Controllers\Controller;
use App\Http\Payloads\DefinitionCollectionPayload;
use App\Models\Definition;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ListController extends Controller
{
    /**
     * List all definitions for a tenant.
     */
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $definitions = $tenant->definitions()
            ->orderBy('created_at', 'desc')
            ->get();

        /** @var array<int, Definition> $definitionItems */
        $definitionItems = $definitions->all();

        $payload = new DefinitionCollectionPayload($definitionItems);

        return new JsonResponse($payload, Response::HTTP_OK);
    }
}
