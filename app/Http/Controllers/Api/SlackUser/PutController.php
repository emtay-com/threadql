<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\SlackUser;

use App\Http\Controllers\Controller;
use App\Models\SlackUser;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class PutController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, SlackUser $slackUser): Response
    {
        if (Gate::denies('update', [$slackUser, $tenant])) {
            abort(404);
        }

        $validated = $request->validate([
            'real_name' => 'sometimes|nullable|string|max:255',
            'display_name' => 'sometimes|nullable|string|max:255',
            'approved' => 'sometimes|boolean',
        ]);

        if (array_key_exists('real_name', $validated)) {
            $slackUser->real_name = $validated['real_name'];
        }

        if (array_key_exists('display_name', $validated)) {
            $slackUser->display_name = $validated['display_name'];
        }

        if (array_key_exists('approved', $validated)) {
            $slackUser->approved = $validated['approved'];
        }

        $slackUser->save();

        return response()->noContent();
    }
}
