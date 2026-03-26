<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SlackUser;

use App\Http\Controllers\Controller;
use App\Models\SlackUser;
use App\Models\Tenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RestoreController extends Controller
{
    public function __invoke(Tenant $tenant, SlackUser $slackUser): Response
    {
        if (Gate::denies('restore', [$slackUser, $tenant])) {
            abort(404);
        }

        $slackUser->restore();

        return response()->noContent();
    }
}
